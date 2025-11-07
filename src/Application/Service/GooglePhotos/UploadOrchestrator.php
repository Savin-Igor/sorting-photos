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
        $ttl = 3600; // 1 час

        // 1. Получить блокировку
        if (!$this->lockManager->acquireLock($lockName, $ttl)) {
            $this->logger->warning('Another orchestrator instance is already running');

            return;
        }

        try {
            // 2. Запустить heartbeat
            $this->startHeartbeat($lockName);

            // 3. Очистить истекшие блокировки
            $this->lockManager->cleanupExpiredLocks();

            // 4. Проверить истекшие сессии
            $this->sessionChecker->checkAndRenewExpiredSessions();

            // 5. Основной цикл
            $lastHeartbeat = \time();
            while (!$this->shouldStop) {
                try {
                    // Обновить блокировку каждые 5 минут
                    if (\time() - $lastHeartbeat > 300) {
                        $this->refreshLockIfNeeded();
                        $lastHeartbeat = \time();
                    }

                    // 5.1. Проверить квоту
                    if (!$this->quotaManager->isQuotaAvailable()) {
                        $resetTime = $this->quotaManager->getResetTime();
                        $this->logger->info('Quota exhausted, scheduling resume', [
                            'reset_time' => $resetTime->format('Y-m-d H:i:s'),
                        ]);
                        break;
                    }

                    // 5.2. Приоритет 1: Незавершенные батчи
                    $batch = $this->batchRepository->findProcessingOrPaused();
                    if ($batch instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch) {
                        try {
                            $this->batchProcessor->processBatch($batch);
                            continue;
                        } catch (QuotaExceededException) {
                            break;
                        }
                    }

                    // 5.3. Приоритет 2: Собрать новый батч
                    $batch = $this->batchCollector->collectBatch();
                    if ($batch instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch) {
                        try {
                            $this->batchProcessor->processBatch($batch);
                            continue;
                        } catch (QuotaExceededException) {
                            break;
                        }
                    }

                    // 5.4. Приоритет 3: Загрузить следующий файл
                    $job = $this->jobRepository->findNextPendingOrResumable();
                    if ($job instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob) {
                        try {
                            $this->uploadService->uploadFile($job);
                            continue;
                        } catch (QuotaExceededException) {
                            break;
                        }
                    }

                    // 5.5. Нет работы
                    $this->logger->info('No more work to do');
                    break;
                } catch (\Exception $e) {
                    $this->logger->error('Error in orchestrator loop', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // Небольшая задержка перед повтором
                    \usleep(1_000_000); // 1 секунда
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

        // Heartbeat будет вызываться в основном цикле
        // Для долгих операций можно добавить проверку времени
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
}
