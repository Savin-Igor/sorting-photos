<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use SortingPhotosByDate\Domain\Event\BatchCreated;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchItem;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadBatchRepositoryPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class BatchCollector
{
    private const int MAX_BATCH_SIZE = 50;
    private const int MAX_BATCH_SIZE_BYTES = 1_000_000_000; // 1 ГБ для видео

    public function __construct(
        private UploadJobRepositoryPort $jobRepository,
        private UploadBatchRepositoryPort $batchRepository,
        private MessageBusInterface $messageBus,
        private LoggerPort $logger,
    ) {
    }

    public function collectBatch(): ?UploadBatch
    {
        // 1. Найти готовые Jobs (UPLOADED, batchId = null)
        $readyJobs = $this->jobRepository->findReadyForBatch(limit: self::MAX_BATCH_SIZE);

        if ([] === $readyJobs) {
            return null;
        }

        // 2. Создать BatchItems с учетом ограничений
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

            // Проверить ограничения
            if ($hasVideo && $totalSize > self::MAX_BATCH_SIZE_BYTES) {
                // Превышен лимит размера для видео
                \array_pop($items); // Убрать последний
                break;
            }

            if (\count($items) >= self::MAX_BATCH_SIZE) {
                // Максимум 50 файлов
                break;
            }
        }

        if ([] === $items) {
            return null;
        }

        // 3. Создать батч
        $batch = UploadBatch::create($items);

        // 4. Привязать Jobs к батчу
        foreach ($readyJobs as $job) {
            if ($job->isReadyForBatch()) {
                $job = $job->assignToBatch($batch->getId());
                $this->jobRepository->save($job);
            }
        }

        $this->batchRepository->save($batch);

        $this->messageBus->dispatch(new BatchCreated(
            batchId: $batch->getId(),
            itemCount: \count($items)
        ));

        $this->logger->info('Batch collected', [
            'batch_id' => $batch->getId()->getId(),
            'item_count' => \count($items),
            'total_size' => $totalSize,
            'has_video' => $hasVideo,
        ]);

        return $batch;
    }
}
