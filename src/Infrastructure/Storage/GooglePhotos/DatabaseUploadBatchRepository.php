<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use Doctrine\DBAL\Connection;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchItem;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchState;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatchId;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJobId;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadBatchRepositoryPort;

final readonly class DatabaseUploadBatchRepository implements UploadBatchRepositoryPort
{
    private const string TABLE_NAME = 'google_photos_upload_batches';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function save(UploadBatch $batch): void
    {
        $itemsJson = \json_encode(
            \array_map(
                fn (BatchItem $item): array => [
                    'job_id' => $item->getJobId()->getId(),
                    'upload_token' => $item->getUploadToken(),
                    'creation_time' => $item->getCreationTime()->format('Y-m-d H:i:s'),
                    'filename' => $item->getFilename(),
                    'mime_type' => $item->getMimeType(),
                    'is_video' => $item->isVideo(),
                    'file_size' => $item->getFileSize(),
                    'processed' => $item->isProcessed(),
                    'error' => $item->getError(),
                ],
                $batch->getItems()
            ),
            \JSON_THROW_ON_ERROR
        );

        $data = [
            'id' => $batch->getId()->getId(),
            'state' => $batch->getState()->value,
            'current_index' => $batch->getCurrentIndex(),
            'total_items' => \count($batch->getItems()),
            'total_size' => $batch->getTotalSize(),
            'items_json' => $itemsJson,
            'error_message' => $batch->getErrorMessage(),
            'quota_reset_time' => $batch->getQuotaResetTime()?->format('Y-m-d H:i:s'),
            'created_at' => $batch->getCreatedAt()->format('Y-m-d H:i:s'),
            'started_at' => $batch->getStartedAt()?->format('Y-m-d H:i:s'),
            'completed_at' => $batch->getCompletedAt()?->format('Y-m-d H:i:s'),
            'updated_at' => $batch->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];

        $existing = $this->connection->fetchOne(
            'SELECT id FROM '.self::TABLE_NAME.' WHERE id = ?',
            [$batch->getId()->getId()]
        );

        if (false !== $existing) {
            $this->connection->update(self::TABLE_NAME, $data, ['id' => $batch->getId()->getId()]);
        } else {
            $this->connection->insert(self::TABLE_NAME, $data);
        }
    }

    public function findById(UploadBatchId $id): ?UploadBatch
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE_NAME.' WHERE id = ?',
            [$id->getId()]
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findProcessingOrPaused(): ?UploadBatch
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE_NAME.'
            WHERE state IN (?, ?)
            ORDER BY created_at ASC
            LIMIT 1',
            [BatchState::PROCESSING->value, BatchState::PAUSED->value]
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findPausedReadyToResume(\DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM '.self::TABLE_NAME.'
            WHERE state = ? AND quota_reset_time <= ?
            ORDER BY quota_reset_time ASC',
            [BatchState::PAUSED->value, $now->format('Y-m-d H:i:s')]
        );

        return \array_map($this->hydrate(...), $rows);
    }

    private function hydrate(array $row): UploadBatch
    {
        $itemsData = \json_decode((string) $row['items_json'], true, 512, \JSON_THROW_ON_ERROR);
        $items = \array_map(
            fn (array $itemData): BatchItem => new BatchItem(
                jobId: UploadJobId::fromString($itemData['job_id']),
                uploadToken: $itemData['upload_token'],
                creationTime: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $itemData['creation_time']) ?: new \DateTimeImmutable(),
                filename: $itemData['filename'],
                mimeType: $itemData['mime_type'],
                isVideo: (bool) $itemData['is_video'],
                fileSize: (int) $itemData['file_size'],
                processed: (bool) ($itemData['processed'] ?? false),
                error: $itemData['error'] ?? null
            ),
            $itemsData
        );

        return UploadBatch::fromArray($row, $items);
    }
}
