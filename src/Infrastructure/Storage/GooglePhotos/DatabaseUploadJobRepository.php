<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use Doctrine\DBAL\Connection;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatchId;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJobId;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadState;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;

final readonly class DatabaseUploadJobRepository implements UploadJobRepositoryPort
{
    private const string TABLE_NAME = 'google_photos_upload_jobs';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function save(UploadJob $job): void
    {
        $data = [
            'id' => $job->getId()->getId(),
            'file_path' => $job->getFilePath()->getPath(),
            'file_size' => $job->getFileSize(),
            'file_hash' => $job->getFileHash()->getHash(),
            'mime_type' => $job->getMimeType(),
            'is_video' => $job->isVideo() ? 1 : 0,
            'state' => $job->getState()->value,
            'resumable_session_uri' => $job->getResumableSession()?->getSessionUri(),
            'uploaded_bytes' => $job->getResumableSession()?->getUploadedBytes() ?? 0,
            'upload_token' => $job->getUploadToken(),
            'batch_id' => $job->getBatchId()?->getId(),
            'creation_time' => $job->getCreationTime()->format('Y-m-d H:i:s'),
            'retry_count' => $job->getRetryCount(),
            'last_error' => $job->getLastError(),
            'last_known_uploaded_bytes' => $job->getLastKnownUploadedBytes(),
            'session_expiration_count' => $job->getSessionExpirationCount(),
            'created_at' => $job->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $job->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];

        // Use INSERT OR REPLACE for atomic operation (SQLite)
        // This eliminates the need for SELECT + INSERT/UPDATE, reducing database queries from 2 to 1
        $sql = 'INSERT OR REPLACE INTO '.self::TABLE_NAME.' (
            id, file_path, file_size, file_hash, mime_type, is_video, state,
            resumable_session_uri, uploaded_bytes, upload_token, batch_id,
            creation_time, retry_count, last_error, last_known_uploaded_bytes,
            session_expiration_count, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $this->connection->executeStatement($sql, [
            $data['id'],
            $data['file_path'],
            $data['file_size'],
            $data['file_hash'],
            $data['mime_type'],
            $data['is_video'],
            $data['state'],
            $data['resumable_session_uri'],
            $data['uploaded_bytes'],
            $data['upload_token'],
            $data['batch_id'],
            $data['creation_time'],
            $data['retry_count'],
            $data['last_error'],
            $data['last_known_uploaded_bytes'],
            $data['session_expiration_count'],
            $data['created_at'],
            $data['updated_at'],
        ]);
    }

    public function findById(UploadJobId $id): ?UploadJob
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

    public function findByHash(FileHash $hash): ?UploadJob
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE_NAME.' WHERE file_hash = ?',
            [$hash->getHash()]
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findNextPendingOrResumable(): ?UploadJob
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE_NAME.'
            WHERE state IN (?, ?)
            ORDER BY created_at ASC
            LIMIT 1',
            [UploadState::PENDING->value, UploadState::UPLOADING->value]
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findReadyForBatch(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM '.self::TABLE_NAME.'
            WHERE state = ? AND batch_id IS NULL
            ORDER BY created_at ASC
            LIMIT ?',
            [UploadState::UPLOADED->value, $limit]
        );

        return \array_map($this->hydrate(...), $rows);
    }

    public function findReadyForBatchBySize(int $maxSize, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM '.self::TABLE_NAME.'
            WHERE state = ? AND batch_id IS NULL AND file_size < ?
            ORDER BY created_at ASC
            LIMIT ?',
            [UploadState::UPLOADED->value, $maxSize, $limit]
        );

        return \array_map($this->hydrate(...), $rows);
    }

    public function findWithExpiredSessions(): array
    {
        $now = new \DateTimeImmutable();
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM '.self::TABLE_NAME.'
            WHERE resumable_session_uri IS NOT NULL
            AND state = ?
            AND uploaded_bytes < file_size',
            [UploadState::UPLOADING->value]
        );

        $expired = [];
        foreach ($rows as $row) {
            $job = $this->hydrate($row);
            if ($job->needsSessionRenewal($now)) {
                $expired[] = $job;
            }
        }

        return $expired;
    }

    public function findByBatchId(UploadBatchId $batchId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM '.self::TABLE_NAME.' WHERE batch_id = ?',
            [$batchId->getId()]
        );

        return \array_map($this->hydrate(...), $rows);
    }

    public function findPausedReadyToResume(\DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM '.self::TABLE_NAME.' WHERE state = ?',
            [UploadState::PAUSED->value]
        );

        return \array_map($this->hydrate(...), $rows);
    }

    private function hydrate(array $row): UploadJob
    {
        return UploadJob::fromArray($row);
    }
}
