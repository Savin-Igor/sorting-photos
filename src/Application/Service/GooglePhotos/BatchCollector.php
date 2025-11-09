<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use SortingPhotosByDate\Domain\Event\BatchCreated;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchItem;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadBatchRepositoryPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;

final readonly class BatchCollector
{
    private const int MAX_BATCH_SIZE = 50;
    private const int MAX_BATCH_SIZE_BYTES = 1_000_000_000; // 1 GB for videos
    private const int SMALL_FILE_THRESHOLD = 2 * 256 * 1024; // 512 KB - files smaller than this use raw upload in batches

    public function __construct(
        private UploadJobRepositoryPort $jobRepository,
        private UploadBatchRepositoryPort $batchRepository,
        private LoggerPort $logger,
    ) {
    }

    /**
     * Collect batch of small files only (for raw upload).
     */
    public function collectSmallFilesBatch(): ?UploadBatch
    {
        // 1. Find ready small Jobs (UPLOADED, batchId = null, file_size < SMALL_FILE_THRESHOLD)
        $readyJobs = $this->jobRepository->findReadyForBatchBySize(
            maxSize: self::SMALL_FILE_THRESHOLD,
            limit: self::MAX_BATCH_SIZE
        );

        if ([] === $readyJobs) {
            return null;
        }

        return $this->createBatch($readyJobs, 'small');
    }

    /**
     * Collect batch of any ready files (legacy method for backward compatibility).
     */
    public function collectBatch(): ?UploadBatch
    {
        // 1. Find ready Jobs (UPLOADED, batchId = null)
        $readyJobs = $this->jobRepository->findReadyForBatch(limit: self::MAX_BATCH_SIZE);

        if ([] === $readyJobs) {
            return null;
        }

        return $this->createBatch($readyJobs, 'mixed');
    }

    /**
     * Create batch from ready jobs.
     *
     * @param array<\SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob> $readyJobs
     */
    private function createBatch(array $readyJobs, string $batchType): ?UploadBatch
    {

        // 2. Create BatchItems considering constraints
        $items = [];
        $totalSize = 0;
        $hasVideo = false;

        foreach ($readyJobs as $job) {
            $batchItem = BatchItem::fromJob($job);
            $items[] = $batchItem;
            $totalSize += $job->getFileSize();

            if ($job->isVideo()) {
                $hasVideo = true;
            }

            // Check constraints
            if ($hasVideo && $totalSize > self::MAX_BATCH_SIZE_BYTES) {
                // Size limit exceeded for videos
                \array_pop($items); // Remove last item
                break;
            }

            if (\count($items) >= self::MAX_BATCH_SIZE) {
                // Maximum 50 files
                break;
            }
        }

        if ([] === $items) {
            return null;
        }

        // 3. Create batch
        $batch = UploadBatch::create($items);

        // 4. Assign Jobs to batch
        foreach ($readyJobs as $job) {
            if ($job->isReadyForBatch()) {
                $job = $job->assignToBatch($batch->getId());
                $this->jobRepository->save($job);
            }
        }

        $this->batchRepository->save($batch);

        // Temporarily disabled - no handler registered
        // $this->messageBus->dispatch(new BatchCreated(
        //     batchId: $batch->getId(),
        //     itemCount: \count($items)
        // ));

        $this->logger->info('Batch collected', [
            'batch_id' => $batch->getId()->getId(),
            'batch_type' => $batchType,
            'item_count' => \count($items),
            'total_size' => $totalSize,
            'has_video' => $hasVideo,
        ]);

        return $batch;
    }
}
