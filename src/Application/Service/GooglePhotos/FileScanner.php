<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use Carbon\Carbon;
use SortingPhotosByDate\Application\Filter\FilterChain;
use SortingPhotosByDate\Application\Service\FileValidation\FileExtensionValidatorInterface;
use SortingPhotosByDate\Application\Service\FileValidation\FileTypeDetectorInterface;
use SortingPhotosByDate\Application\Service\GooglePhotos\JobPersistenceStrategy\JobPersistenceStrategyInterface;
use SortingPhotosByDate\Domain\Search\FileSearchCriteria;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Infrastructure\Metadata\FilenameDateExtractor;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\MetadataExtractorPort;
use SortingPhotosByDate\Ports\ScannerPort;
use SortingPhotosByDate\Ports\Search\FileSearcherPort;
use SortingPhotosByDate\Ports\Search\SearcherNotAvailableException;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryReadPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryWritePort;

final readonly class FileScanner
{
    /**
     * @param array<string, mixed>|null $searchConfig Search configuration (null = disabled)
     */
    public function __construct(
        private ScannerPort $scanner,
        private MetadataExtractorPort $metadataExtractor,
        private UploadJobRepositoryReadPort $jobRepository,
        private LoggerPort $logger,
        private FilenameDateExtractor $filenameDateExtractor,
        private FileExtensionValidatorInterface $extensionValidator,
        private FileTypeDetectorInterface $typeDetector,
        private JobCreatorInterface $jobCreator,
        private ?FilterChain $filterChain = null,
        private ?FileSearcherPort $fileSearcher = null,
        private ?array $searchConfig = null,
        private ?JobPersistenceStrategyInterface $persistenceStrategy = null,
        private ?UploadJobRepositoryWritePort $jobRepositoryWrite = null,
    ) {
    }

    public function scanAndCreateJobs(string $sourcePath): int
    {
        // Use fast file search if enabled and available
        if ($this->fileSearcher instanceof FileSearcherPort
            && null !== $this->searchConfig
            && ($this->searchConfig['enabled'] ?? false)) {
            return $this->scanWithSearch($sourcePath);
        }

        // Fallback to recursive scanning (backward compatibility)
        return $this->scanRecursive($sourcePath);
    }

    /**
     * Process already found files without searching again.
     * Used when files are already discovered (e.g., from command search).
     *
     * @param \SortingPhotosByDate\Domain\ValueObjects\FilePath[] $filePaths Already found file paths
     *
     * @return int Number of jobs created
     */
    public function processFoundFiles(array $filePaths): int
    {
        $createdCount = 0;
        $skippedCount = 0;

        $this->logger->info('Processing found files', [
            'total_files' => \count($filePaths),
        ]);

        foreach ($filePaths as $filePath) {
            $result = $this->processFile($filePath);
            if ($result) {
                ++$createdCount;
            } else {
                ++$skippedCount;
            }
        }

        // Flush any buffered jobs if using batch strategy
        if ($this->persistenceStrategy instanceof JobPersistenceStrategyInterface) {
            $flushedCount = $this->persistenceStrategy->flush();
            if ($flushedCount > 0) {
                $this->logger->info('Flushed buffered jobs', [
                    'flushed_count' => $flushedCount,
                ]);
            }
        }

        $this->logger->info('File processing completed', [
            'created' => $createdCount,
            'skipped' => $skippedCount,
        ]);

        return $createdCount;
    }

    /**
     * Scan using fast file search.
     */
    private function scanWithSearch(string $sourcePath): int
    {
        if (!$this->fileSearcher instanceof FileSearcherPort) {
            return $this->scanRecursive($sourcePath);
        }

        $criteria = FileSearchCriteria::fromConfig($this->searchConfig ?? []);

        $this->logger->info('Starting file search', [
            'directory' => $sourcePath,
            'searcher' => $this->fileSearcher->getName(),
            'strategy' => $this->searchConfig['strategy'] ?? 'unknown',
        ]);

        $startTime = \microtime(true);
        $createdCount = 0;
        $skippedCount = 0;

        try {
            $filePaths = $this->fileSearcher->search($sourcePath, $criteria);

            foreach ($filePaths as $filePath) {
                $result = $this->processFile($filePath);
                if ($result) {
                    ++$createdCount;
                } else {
                    ++$skippedCount;
                }
            }
        } catch (SearcherNotAvailableException $e) {
            $this->logger->warning('File searcher not available, falling back to recursive scan', [
                'error' => $e->getMessage(),
            ]);

            return $this->scanRecursive($sourcePath);
        }

        // Flush any buffered jobs if using batch strategy
        if ($this->persistenceStrategy instanceof JobPersistenceStrategyInterface) {
            $flushedCount = $this->persistenceStrategy->flush();
            if ($flushedCount > 0) {
                $this->logger->info('Flushed buffered jobs', [
                    'flushed_count' => $flushedCount,
                ]);
            }
        }

        $duration = \microtime(true) - $startTime;
        $totalFiles = $createdCount + $skippedCount;

        $this->logger->info('File search completed', [
            'directory' => $sourcePath,
            'found_files' => $totalFiles,
            'created' => $createdCount,
            'skipped' => $skippedCount,
            'duration_seconds' => \round($duration, 2),
            'files_per_second' => $totalFiles > 0 ? \round($totalFiles / $duration, 2) : 0,
        ]);

        return $createdCount;
    }

    /**
     * Scan recursively (backward compatibility).
     */
    private function scanRecursive(string $sourcePath): int
    {
        $createdCount = 0;
        $skippedCount = 0;

        foreach ($this->scanner->scan($sourcePath) as $filePath) {
            $result = $this->processFile($filePath);
            if ($result) {
                ++$createdCount;
            } else {
                ++$skippedCount;
            }
        }

        // Flush any buffered jobs if using batch strategy
        if ($this->persistenceStrategy instanceof JobPersistenceStrategyInterface) {
            $flushedCount = $this->persistenceStrategy->flush();
            if ($flushedCount > 0) {
                $this->logger->info('Flushed buffered jobs', [
                    'flushed_count' => $flushedCount,
                ]);
            }
        }

        $this->logger->info('File scanning completed', [
            'created' => $createdCount,
            'skipped' => $skippedCount,
        ]);

        return $createdCount;
    }

    /**
     * Process a single file and create UploadJob if needed.
     *
     * @return bool True if job was created, false if skipped
     */
    private function processFile(\SortingPhotosByDate\Domain\ValueObjects\FilePath $filePath): bool
    {
        try {
            // 1. Check deduplication
            $hash = FileHash::fromFile($filePath->getPath());
            $existingJob = $this->jobRepository->findByHash($hash);

            // Skip creating a new job if one already exists for the same content (any state).
            if ($existingJob instanceof UploadJob) {
                $this->logger->info('Duplicate detected during scan: job already exists, skipping', [
                    'file_path' => $filePath->getPath(),
                    'hash' => $hash->getHash(),
                    'existing_state' => $existingJob->getState()->value,
                    'existing_job_id' => $existingJob->getId()->getId(),
                ]);

                return false;
            }

            // 2. Early check: skip obviously non-media files by extension
            // This avoids unnecessary metadata extraction for known non-media files
            if ($this->extensionValidator->isNonMediaExtension($filePath->getPath())) {
                $this->logger->info('Skipping non-media file during scan (by extension)', [
                    'file_path' => $filePath->getPath(),
                    'extension' => \strtolower(\pathinfo($filePath->getPath(), \PATHINFO_EXTENSION)),
                ]);

                return false;
            }

            // 2.3. Validate file extension against search configuration
            // If search config specifies extensions, only files with those extensions should be processed
            // This prevents videos from being processed when search is configured for images only
            if (!$this->extensionValidator->matchesSearchExtensions($filePath->getPath(), $this->searchConfig)) {
                $this->logger->info('Skipping file - extension not in search configuration', [
                    'file_path' => $filePath->getPath(),
                    'extension' => \strtolower(\pathinfo($filePath->getPath(), \PATHINFO_EXTENSION)),
                    'allowed_extensions' => $this->searchConfig['extensions'] ?? null,
                ]);

                return false;
            }

            // 2.5. Early filtering (before metadata extraction)
            if ($this->filterChain instanceof FilterChain && $this->filterChain->shouldSkipEarly($filePath, [])) {
                return false; // File filtered out - won't be added to database
            }

            // 3. Extract metadata
            $metadata = $this->metadataExtractor->extract($filePath->getPath());

            // 4. Check if file is a media file (image or video) - Google Photos only supports these
            // Use extension as fallback if MIME type is not properly detected (e.g., application/octet-stream)
            if (!$this->typeDetector->isMediaFile($metadata->getMimeType(), $filePath->getPath())) {
                $this->logger->info('Skipping non-media file during scan (by MIME type and extension)', [
                    'file_path' => $filePath->getPath(),
                    'mime_type' => $metadata->getMimeType(),
                    'extension' => \strtolower(\pathinfo($filePath->getPath(), \PATHINFO_EXTENSION)),
                ]);

                return false;
            }

            $date = $this->metadataExtractor->extractDate($filePath->getPath());

            // 5. Determine type (use extension fallback if MIME type is generic)
            $isVideo = $this->typeDetector->isVideo($metadata->getMimeType(), $filePath->getPath());

            // Check if date was extracted from filename (especially for videos)
            $filenameDate = $this->filenameDateExtractor->extract($filePath->getPath());
            if ($filenameDate instanceof Carbon) {
                $extractedDate = $date->getDateTime();

                // Compare dates: check if dates match (considering different formats)
                // Some filename formats only have date without time (00:00:00), so we compare:
                // 1. If filename date has time (not 00:00:00), compare with full precision (within 1 second)
                // 2. If filename date has no time (00:00:00), compare only date part (year, month, day)
                $filenameHasTime = !(0 === $filenameDate->hour && 0 === $filenameDate->minute && 0 === $filenameDate->second);

                $datesMatch = false;
                if ($filenameHasTime) {
                    // Full date-time comparison (allow 1 second difference)
                    $dateDiff = abs($extractedDate->diffInSeconds($filenameDate));
                    $datesMatch = $dateDiff <= 1;
                } else {
                    // Date-only comparison (compare year, month, day)
                    $datesMatch = $extractedDate->year === $filenameDate->year
                        && $extractedDate->month === $filenameDate->month
                        && $extractedDate->day === $filenameDate->day;
                }

                if ($datesMatch) {
                    // Date matches filename date
                    $this->logger->info('Date extracted from filename during scan', [
                        'file_path' => $filePath->getPath(),
                        'is_video' => $isVideo,
                        'extracted_date' => $extractedDate->format('Y-m-d H:i:s'),
                        'filename_date' => $filenameDate->format('Y-m-d H:i:s'),
                        'date_only_match' => !$filenameHasTime,
                        'source' => 'filename',
                    ]);
                }
            }

            // 6. Normalize MIME type if it was detected incorrectly (application/octet-stream)
            // Use extension-based MIME type mapping as fallback
            $mimeType = $metadata->getMimeType();
            if ('application/octet-stream' === $mimeType || '' === $mimeType) {
                $normalizedMimeType = $this->typeDetector->getMimeTypeFromExtension($filePath->getPath());
                if (null !== $normalizedMimeType) {
                    $mimeType = $normalizedMimeType;
                    $this->logger->debug('Normalized MIME type from extension', [
                        'file_path' => $filePath->getPath(),
                        'original_mime_type' => $metadata->getMimeType(),
                        'normalized_mime_type' => $mimeType,
                    ]);
                }
            }

            // 6.5. Filtering with metadata (after metadata extraction)
            $creationTime = $date->getDateTime();
            // MediaDate::getDateTime() always returns Carbon
            $creationTimeImmutable = \DateTimeImmutable::createFromMutable($creationTime->toDateTime());

            if ($this->filterChain instanceof FilterChain && $this->filterChain->shouldSkip($filePath, [
                'mime_type' => $mimeType,
                'file_size' => $metadata->getFileSize(),
                'is_video' => $isVideo,
                'metadata' => $metadata,
                'creation_date' => $creationTimeImmutable,
            ])) {
                return false; // UploadJob will NOT be created, file will NOT be added to database
            }

            // 7. Create UploadJob
            $creationTime = $creationTimeImmutable;

            $job = $this->jobCreator->createJob(
                filePath: $filePath,
                hash: $hash,
                metadata: $metadata,
                mimeType: $mimeType,
                isVideo: $isVideo,
                creationTime: $creationTime,
            );

            // Use persistence strategy if available, otherwise fallback to direct repository save
            if ($this->persistenceStrategy instanceof JobPersistenceStrategyInterface) {
                $this->persistenceStrategy->persist($job);
            } elseif ($this->jobRepositoryWrite instanceof UploadJobRepositoryWritePort) {
                $this->jobRepositoryWrite->save($job);
            } elseif ($this->jobRepository instanceof UploadJobRepositoryPort) {
                // Fallback: if repository implements full interface, use it
                $this->jobRepository->save($job);
            } else {
                throw new \RuntimeException('No write repository available for saving job');
            }

            $this->logger->debug('Upload job created', [
                'job_id' => $job->getId()->getId(),
                'file_path' => $filePath->getPath(),
                'file_size' => $metadata->getFileSize(),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create upload job', [
                'file_path' => $filePath->getPath(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
