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

                if ($existingJob instanceof UploadJob && 'completed' === $existingJob->getState()->value) {
                    $this->logger->debug('File already uploaded, skipping', [
                        'file_path' => $filePath->getPath(),
                        'hash' => $hash->getHash(),
                    ]);
                    ++$skippedCount;
                    continue;
                }

                // 2. Extract metadata
                $metadata = $this->metadataExtractor->extract($filePath->getPath());
                $date = $this->metadataExtractor->extractDate($filePath->getPath());

                // 3. Determine type
                $isVideo = $this->isVideo($metadata->getMimeType());

                // 4. Create UploadJob
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
}
