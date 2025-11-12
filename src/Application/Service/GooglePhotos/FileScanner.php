<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\MetadataExtractorPort;
use SortingPhotosByDate\Ports\ScannerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;

final readonly class FileScanner
{
    public function __construct(
        private ScannerPort $scanner,
        private MetadataExtractorPort $metadataExtractor,
        private UploadJobRepositoryPort $jobRepository,
        private LoggerPort $logger,
    ) {
    }

    public function scanAndCreateJobs(string $sourcePath): int
    {
        $createdCount = 0;
        $skippedCount = 0;

        foreach ($this->scanner->scan($sourcePath) as $filePath) {
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
                    ++$skippedCount;
                    continue;
                }

                // 2. Early check: skip obviously non-media files by extension
                // This avoids unnecessary metadata extraction for known non-media files
                if ($this->isNonMediaExtension($filePath->getPath())) {
                    $this->logger->info('Skipping non-media file during scan (by extension)', [
                        'file_path' => $filePath->getPath(),
                        'extension' => \strtolower(\pathinfo($filePath->getPath(), \PATHINFO_EXTENSION)),
                    ]);
                    ++$skippedCount;
                    continue;
                }

                // 3. Extract metadata
                $metadata = $this->metadataExtractor->extract($filePath->getPath());

                // 4. Check if file is a media file (image or video) - Google Photos only supports these
                if (!$this->isMediaFile($metadata->getMimeType())) {
                    $this->logger->info('Skipping non-media file during scan (by MIME type)', [
                        'file_path' => $filePath->getPath(),
                        'mime_type' => $metadata->getMimeType(),
                        'extension' => \strtolower(\pathinfo($filePath->getPath(), \PATHINFO_EXTENSION)),
                    ]);
                    ++$skippedCount;
                    continue;
                }

                $date = $this->metadataExtractor->extractDate($filePath->getPath());

                // 5. Determine type
                $isVideo = $this->isVideo($metadata->getMimeType());

                // 6. Create UploadJob
                $creationTime = $date->getDateTime();
                // MediaDate::getDateTime() always returns Carbon
                $creationTime = \DateTimeImmutable::createFromMutable($creationTime->toDateTime());

                $job = UploadJob::create(
                    filePath: $filePath,
                    fileSize: $metadata->getFileSize(),
                    fileHash: $hash,
                    mimeType: $metadata->getMimeType(),
                    isVideo: $isVideo,
                    creationTime: $creationTime,
                );

                $this->jobRepository->save($job);
                ++$createdCount;

                $this->logger->debug('Upload job created', [
                    'job_id' => $job->getId()->getId(),
                    'file_path' => $filePath->getPath(),
                    'file_size' => $metadata->getFileSize(),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to create upload job', [
                    'file_path' => $filePath->getPath(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('File scanning completed', [
            'created' => $createdCount,
            'skipped' => $skippedCount,
        ]);

        return $createdCount;
    }

    private function isVideo(string $mimeType): bool
    {
        return \str_starts_with($mimeType, 'video/');
    }

    /**
     * Check if file is a media file supported by Google Photos API.
     * Google Photos only supports images and videos.
     */
    private function isMediaFile(string $mimeType): bool
    {
        return \str_starts_with($mimeType, 'image/') || \str_starts_with($mimeType, 'video/');
    }

    /**
     * Check if file has a non-media extension that should be skipped early.
     * This helps avoid unnecessary metadata extraction for known non-media files.
     *
     * @param string $filePath Full path to the file
     *
     * @return bool True if file should be skipped based on extension
     */
    private function isNonMediaExtension(string $filePath): bool
    {
        $extension = \strtolower(\pathinfo($filePath, \PATHINFO_EXTENSION));

        // Common non-media file extensions that should never be uploaded to Google Photos
        $nonMediaExtensions = [
            // Code files
            'php', 'js', 'ts', 'jsx', 'tsx', 'py', 'java', 'cpp', 'c', 'h', 'hpp', 'cs', 'go', 'rs', 'rb', 'swift', 'kt',
            // Config/data files
            'json', 'xml', 'yaml', 'yml', 'toml', 'ini', 'conf', 'config', 'env', 'properties',
            // Text files
            'txt', 'md', 'markdown', 'readme', 'log', 'csv', 'tsv',
            // Archive files
            'zip', 'tar', 'gz', 'bz2', 'xz', '7z', 'rar', 'cab',
            // Executable files
            'exe', 'dll', 'so', 'dylib', 'bin', 'sh', 'bat', 'cmd', 'ps1',
            // Database files
            'db', 'sqlite', 'sql', 'sqlite3',
            // Document files (not supported by Google Photos)
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf',
            // Font files
            'ttf', 'otf', 'woff', 'woff2', 'eot',
            // Other
            'lock', 'cache', 'tmp', 'temp', 'bak', 'backup', 'old',
        ];

        return \in_array($extension, $nonMediaExtensions, true);
    }
}
