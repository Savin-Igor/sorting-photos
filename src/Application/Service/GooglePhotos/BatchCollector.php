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
    private const int MAX_BATCH_SIZE_BYTES = 1_000_000_000; // 512 KB - files smaller than this use raw upload in batches

    public function __construct(
        private UploadJobRepositoryPort $jobRepository,
        private UploadBatchRepositoryPort $batchRepository,
        private LoggerPort $logger,
    ) {
    }

    /**
     * Collect batch of ready files.
     */
    public function collectBatch(): ?UploadBatch
    {
        // Find ready Jobs (UPLOADED, batchId = null)
        $readyJobs = $this->jobRepository->findReadyForBatch(limit: self::MAX_BATCH_SIZE);

        $this->logger->debug('Checking for ready jobs to collect into batch', [
            'found_count' => \count($readyJobs),
        ]);

        if ([] === $readyJobs) {
            return null;
        }

        return $this->createBatch($readyJobs, 'all');
    }

    /**
     * Collect batch of small files only (for raw upload).
     */
    #[\Deprecated(message: 'Use collectBatch() instead - all files use raw upload now')]
    public function collectSmallFilesBatch(): ?UploadBatch
    {
        return $this->collectBatch();
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
            $proposedTotalSize = $totalSize + $job->getFileSize();
            $wouldHaveVideo = $hasVideo || $job->isVideo();

            // Check constraints BEFORE adding item
            // If adding this item would exceed size limit for videos, stop here
            if ($wouldHaveVideo && $proposedTotalSize > self::MAX_BATCH_SIZE_BYTES) {
                // Size limit would be exceeded - stop adding items
                // But keep what we have so far (if any)
                break;
            }

            // Check file count limit
            if (\count($items) >= self::MAX_BATCH_SIZE) {
                // Maximum 50 files
                break;
            }

            // Safe to add this item
            $batchItem = BatchItem::fromJob($job);
            $items[] = $batchItem;
            $totalSize = $proposedTotalSize;

            if ($job->isVideo()) {
                $hasVideo = true;
            }
        }

        if ([] === $items) {
            $this->logger->debug('Cannot create batch: no items fit within constraints', [
                'ready_jobs_count' => \count($readyJobs),
                'max_batch_size_bytes' => self::MAX_BATCH_SIZE_BYTES,
            ]);

            return null;
        }

        // 3. Create batch
        $batch = UploadBatch::create($items);

        // 4. Assign Jobs to batch - ONLY for jobs that are actually in the batch
        // Important: $items may contain fewer elements than $readyJobs if constraints were hit
        foreach ($items as $batchItem) {
            $job = $this->jobRepository->findById($batchItem->getJobId());
            if ($job instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob && $job->isReadyForBatch()) {
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
