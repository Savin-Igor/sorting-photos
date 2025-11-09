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
            $this->sessionChecker->checkAndRenewExpiredSessions();

            // 5. Main loop
            $lastHeartbeat = \time();
            while (!$this->shouldStop) {
                try {
                    // Refresh lock every 5 minutes
                    if (\time() - $lastHeartbeat > 300) {
                        $this->refreshLockIfNeeded();
                        $lastHeartbeat = \time();
                    }

                    // 5.1. Check quota
                    if (!$this->quotaManager->isQuotaAvailable()) {
                        $resetTime = $this->quotaManager->getResetTime();
                        $this->logger->info('Quota exhausted, scheduling resume', [
                            'reset_time' => $resetTime->format('Y-m-d H:i:s'),
                        ]);
                        break;
                    }

                    // 5.2. Priority 1: Incomplete batches
                    $batch = $this->batchRepository->findProcessingOrPaused();
                    if ($batch instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch) {
                        // Skip if batch is already completed
                        if ($batch->getState()->value === \SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchState::COMPLETED->value) {
                            continue;
                        }
                        $this->logger->debug('Processing incomplete batch', ['batch_id' => $batch->getId()]);
                        try {
                            $this->batchProcessor->processBatch($batch);
                            continue;
                        } catch (QuotaExceededException) {
                            break;
                        }
                    }

                    // 5.3. Priority 2: Upload next file (using raw upload for all files)
                    // Upload files first, then collect them into batches
                    $this->logger->debug('Looking for pending or resumable jobs to upload');
                    $job = $this->jobRepository->findNextPendingOrResumable();
                    if ($job instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob) {
                        $this->logger->info('Processing file', [
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
                            continue; // Continue to upload next file
                        } catch (QuotaExceededException) {
                            break;
                        }
                    } else {
                        $this->logger->debug('No pending or resumable jobs found for upload');
                    }

                    // 5.4. Priority 3: Collect new batch of uploaded files
                    // Only collect batches when there are no more files to upload
                    $this->logger->debug('Checking for uploaded files to collect into batch');
                    $batch = $this->batchCollector->collectBatch();
                    if ($batch instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch) {
                        $this->logger->info('Collected new batch', ['batch_id' => $batch->getId()]);
                        try {
                            $this->batchProcessor->processBatch($batch);
                            continue;
                        } catch (QuotaExceededException) {
                            break;
                        }
                    } else {
                        $this->logger->debug('No uploaded files found to collect into batch');
                    }

                    // 5.5. No work
                    $this->logger->info('No more work to do');
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
