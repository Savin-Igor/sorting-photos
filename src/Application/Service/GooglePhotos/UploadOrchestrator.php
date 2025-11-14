<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadBatchRepositoryPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;

final class UploadOrchestrator
{
    private bool $shouldStop = false;
    private ?\Closure $heartbeatCallback = null;
    private ?\DateTimeImmutable $uploadBackoffUntil = null;
    private ?\DateTimeImmutable $batchBackoffUntil = null;

    /**
     * Read minimal batch size from env (GOOGLE_PHOTOS_BATCH_MIN_SIZE), default 40.
     * Clamped to [1, 50].
     */
    private function getMinBatchSize(): int
    {
        $value = getenv('GOOGLE_PHOTOS_BATCH_MIN_SIZE') ?: ($_ENV['GOOGLE_PHOTOS_BATCH_MIN_SIZE'] ?? null);
        $min = \is_numeric($value) ? (int) $value : 40;
        if ($min < 1) {
            $min = 1;
        } elseif ($min > 50) {
            $min = 50;
        }

        return $min;
    }

    public function __construct(
        private readonly DistributedLockManager $lockManager,
        private readonly ResumableUploadService $uploadService,
        private readonly BatchCollector $batchCollector,
        private readonly BatchProcessor $batchProcessor,
        private readonly QuotaManager $quotaManager,
        private readonly SessionExpirationChecker $sessionChecker,
        private readonly UploadJobRepositoryPort $jobRepository,
        private readonly UploadBatchRepositoryPort $batchRepository,
        private readonly LoggerPort $logger,
    ) {
    }

    public function run(): void
    {
        $lockName = 'google_photos_upload_orchestrator';
        $ttl = 3600; // 1 hour

        // 1. Acquire lock
        if (!$this->lockManager->acquireLock($lockName, $ttl)) {
            $this->logger->warning('Another orchestrator instance is already running');

            return;
        }

        try {
            $this->resetStaleProcessingBatches();

            // 2. Start heartbeat
            $this->startHeartbeat($lockName);

            // 3. Clean up expired locks
            $this->lockManager->cleanupExpiredLocks();

            // 4. Check expired sessions
            $this->logger->debug('Checking expired sessions');
            $this->sessionChecker->checkAndRenewExpiredSessions();
            $this->logger->debug('Expired sessions check completed');

            // 5. Main loop
            $lastHeartbeat = \time();
            $loopIteration = 0;
            while (!$this->shouldStop) {
                ++$loopIteration;
                $this->logger->debug(\sprintf('Orchestrator loop iteration %d', $loopIteration));
                $now = new \DateTimeImmutable();
                try {
                    // Refresh lock every 5 minutes
                    if (\time() - $lastHeartbeat > 300) {
                        $this->refreshLockIfNeeded();
                        $lastHeartbeat = \time();
                    }

                    // 5.1. Check quota
                    if (!$this->quotaManager->isQuotaAvailable()) {
                        $resetTime = $this->quotaManager->getResetTime();
                        $this->logger->warning('Quota exhausted, scheduling resume', [
                            'reset_time' => $resetTime->format('Y-m-d H:i:s'),
                        ]);
                        break;
                    }

                    // 5.1.5. Resume paused batches if quota is available
                    $batchBackoffActive = $this->batchBackoffUntil instanceof \DateTimeImmutable && $now < $this->batchBackoffUntil;
                    if (!$batchBackoffActive) {
                        $pausedBatches = $this->batchRepository->findPausedReadyToResume($now);
                        foreach ($pausedBatches as $pausedBatch) {
                            $this->logger->info('Resuming paused batch', [
                                'batch_id' => $pausedBatch->getId()->getId(),
                            ]);
                            $resumedBatch = $pausedBatch->resume();
                            $this->batchRepository->save($resumedBatch);
                        }
                    }

                    // 5.1.6. Resume paused jobs if quota is available
                    $pausedJobs = $this->jobRepository->findPausedReadyToResume($now);
                    foreach ($pausedJobs as $pausedJob) {
                        $this->logger->info('Resuming paused job', [
                            'job_id' => $pausedJob->getId()->getId(),
                            'file_path' => $pausedJob->getFilePath()->getPath(),
                        ]);
                        $resumedJob = $pausedJob->resume();
                        $this->jobRepository->save($resumedJob);
                    }

                    // 5.2. Priority 1: Incomplete batches (READY or PROCESSING)
                    if (!$batchBackoffActive) {
                        $batch = $this->batchRepository->findIncomplete();
                        if ($batch instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch) {
                            // Skip if batch is already completed
                            if ($batch->getState()->value === \SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchState::COMPLETED->value) {
                                continue;
                            }
                            $this->logger->debug('Processing incomplete batch', [
                                'batch_id' => $batch->getId()->getId(),
                                'state' => $batch->getState()->value,
                            ]);
                            try {
                                $this->batchProcessor->processBatch($batch);
                                continue;
                            } catch (QuotaExceededException $e) {
                                $this->batchBackoffUntil = $e->getResetTime();
                                $this->logger->warning('Batch processing paused due to quota (incomplete batch)', [
                                    'reset_time' => $this->batchBackoffUntil->format('Y-m-d H:i:s'),
                                ]);
                            } catch (\Exception $e) {
                                // If batch processing fails with an exception, check if batch should be marked as FAILED
                                // This handles cases where batch processing fails due to unsupported media or other critical errors
                                $this->logger->error('Batch processing failed with exception', [
                                    'batch_id' => $batch->getId()->getId(),
                                    'error' => $e->getMessage(),
                                    'exception' => $e::class,
                                ]);

                                // Re-fetch batch to get latest state (may have been updated by BatchProcessor)
                                $updatedBatch = $this->batchRepository->findById($batch->getId());
                                // If batch is already FAILED, skip it
                                if ($updatedBatch instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch && $updatedBatch->getState()->value === \SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchState::FAILED->value) {
                                    $this->logger->info('Batch already marked as FAILED, skipping', [
                                        'batch_id' => $updatedBatch->getId()->getId(),
                                    ]);
                                    continue;
                                }

                                // Continue to next iteration - batch will be retried or handled by scheduler
                                continue;
                            }
                        }
                    } else {
                        $this->logger->debug('Skipping incomplete batch due to backoff', [
                            'resume_at' => $this->batchBackoffUntil->format('Y-m-d H:i:s'),
                        ]);
                    }

                    // 5.3. Priority 2: Upload next file (using raw upload for all files)
                    // Upload files first, then collect them into batches
                    $this->logger->debug('Looking for pending or resumable jobs to upload');
                    $job = $this->jobRepository->findNextPendingOrResumable();
                    if ($job instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob) {
                        // Pre-check: Verify file exists before processing
                        // This prevents wasting time on files that don't exist (moved or external drive disconnected)
                        $filePath = $job->getFilePath()->getPath();
                        if (!\file_exists($filePath)) {
                            $this->logger->warning('File not found before upload, marking as NOT_FOUND', [
                                'job_id' => $job->getId()->getId(),
                                'file_path' => $filePath,
                            ]);
                            $job = $job->markAsNotFound();
                            $this->jobRepository->save($job);
                            continue; // Skip to next iteration, don't process this file
                        }

                        $this->logger->info('Uploading file', [
                            'job_id' => $job->getId(),
                            'file_path' => $job->getFilePath(),
                            'file_size' => $job->getFileSize(),
                            'state' => $job->getState()->value,
                        ]);
                        try {
                            $this->uploadService->uploadFile($job);
                            $this->logger->info('File upload completed', [
                                'job_id' => $job->getId(),
                            ]);

                            // Opportunistic batch collection right after a successful upload:
                            // collect batches when enough ready items accumulated (threshold), to avoid tiny batches.
                            // Use lower threshold (10) for opportunistic collection to process batches more frequently.
                            try {
                                $minBatchSize = $this->getMinBatchSize();
                                $opportunisticThreshold = \min(10, $minBatchSize); // Lower threshold for opportunistic collection
                                $ready = $this->jobRepository->findReadyForBatch(50);
                                $readyCount = \count($ready);
                                if ($readyCount >= $opportunisticThreshold) {
                                    $collected = $this->batchCollector->collectBatch();
                                    if ($collected instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch) {
                                        $this->logger->info('Collected batch after upload (opportunistic)', [
                                            'batch_id' => $collected->getId()->getId(),
                                            'ready_count' => $readyCount,
                                            'opportunistic_threshold' => $opportunisticThreshold,
                                            'min_batch_size' => $minBatchSize,
                                        ]);
                                        try {
                                            $this->batchProcessor->processBatch($collected);
                                        } catch (QuotaExceededException) {
                                            // If quota exhausted during batch, break outer loop to reschedule
                                            break;
                                        }
                                    } else {
                                        $this->logger->debug('Batch collection returned null (constraints not met)', [
                                            'ready_count' => $readyCount,
                                            'opportunistic_threshold' => $opportunisticThreshold,
                                        ]);
                                    }
                                } else {
                                    $this->logger->debug('Skipping batch collection (not enough ready items yet)', [
                                        'ready_count' => $readyCount,
                                        'opportunistic_threshold' => $opportunisticThreshold,
                                    ]);
                                }
                            } catch (\Exception $e) {
                                $this->logger->warning('Batch collection after upload failed', [
                                    'error' => $e->getMessage(),
                                ]);
                            }
                            continue; // Continue to upload next file
                        } catch (QuotaExceededException $e) {
                            $this->uploadBackoffUntil = $e->getResetTime();
                            $this->logger->warning('Upload paused due to quota', [
                                'reset_time' => $this->uploadBackoffUntil->format('Y-m-d H:i:s'),
                            ]);
                        }
                    } else {
                        $this->logger->debug('No pending or resumable jobs found for upload');
                    }

                    // 5.4. Priority 3: Collect new batch of uploaded files
                    // Only collect batches when there are no more files to upload
                    if (!($this->batchBackoffUntil instanceof \DateTimeImmutable && $now < $this->batchBackoffUntil)) {
                        $this->logger->debug('Checking for uploaded files to collect into batch');
                        $batch = $this->batchCollector->collectBatch();
                        if ($batch instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch) {
                            $this->logger->debug('Collected new batch', ['batch_id' => $batch->getId()]);
                            try {
                                $this->batchProcessor->processBatch($batch);
                                continue;
                            } catch (QuotaExceededException $e) {
                                $this->batchBackoffUntil = $e->getResetTime();
                                $this->logger->warning('Batch processing paused due to quota (final collect)', [
                                    'reset_time' => $this->batchBackoffUntil->format('Y-m-d H:i:s'),
                                ]);
                            }
                        } else {
                            $this->logger->debug('No uploaded files found to collect into batch');
                        }
                    } else {
                        $this->logger->debug('Skipping final batch collection due to backoff', [
                            'resume_at' => $this->batchBackoffUntil->format('Y-m-d H:i:s'),
                        ]);
                    }

                    // 5.5. No work
                    $this->logger->debug('No more work to do');
                    break;
                } catch (\Exception $e) {
                    $errorDetails = \sprintf(
                        "Error in orchestrator loop: %s\nFile: %s:%d\nTrace:\n%s\n",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        $e->getTraceAsString()
                    );
                    \fwrite(\STDERR, $errorDetails);

                    $this->logger->error('Error in orchestrator loop', [
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // Small delay before retry
                    \usleep(1_000_000); // 1 second
                }
            }
        } finally {
            $this->stopHeartbeat();
            $this->lockManager->releaseLock($lockName);
        }
    }

    public function shutdown(): void
    {
        $this->shouldStop = true;
        $this->logger->info('Orchestrator shutdown requested');
    }

    private function startHeartbeat(string $lockName): void
    {
        $this->heartbeatCallback = function () use ($lockName): void {
            $this->lockManager->refreshLock($lockName);
        };

        // Heartbeat will be called in the main loop
        // For long operations, time checks can be added
    }

    private function stopHeartbeat(): void
    {
        $this->heartbeatCallback = null;
    }

    private function refreshLockIfNeeded(): void
    {
        if ($this->heartbeatCallback instanceof \Closure) {
            ($this->heartbeatCallback)();
        }
    }

    private function resetStaleProcessingBatches(): void
    {
        try {
            $timeout = new \DateTimeImmutable('-15 minutes');
            $resetCount = $this->batchRepository->resetStaleProcessingBatches($timeout);

            if ($resetCount > 0) {
                $this->logger->info(\sprintf('Reset %d stale processing batches.', $resetCount));
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to reset stale batches', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
