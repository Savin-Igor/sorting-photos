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
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryReadPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryWritePort;

final readonly class DatabaseUploadJobRepository implements UploadJobRepositoryPort, UploadJobRepositoryReadPort, UploadJobRepositoryWritePort
{
    private const string TABLE_NAME = 'google_photos_upload_jobs';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function save(UploadJob $job): void
    {
        $data = $this->prepareJobData($job);

        // Use INSERT ... ON CONFLICT to only update Pending jobs
        // Completed/Uploaded/Failed jobs remain untouched
        // New jobs are inserted as Pending
        $sql = 'INSERT INTO '.self::TABLE_NAME.' (
            id, file_path, file_size, file_hash, mime_type, is_video, state,
            resumable_session_uri, uploaded_bytes, upload_token, batch_id,
            creation_time, retry_count, last_error, last_known_uploaded_bytes,
            session_expiration_count, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(id) DO UPDATE SET
            file_path = excluded.file_path,
            file_size = excluded.file_size,
            file_hash = excluded.file_hash,
            mime_type = excluded.mime_type,
            is_video = excluded.is_video,
            resumable_session_uri = excluded.resumable_session_uri,
            uploaded_bytes = excluded.uploaded_bytes,
            upload_token = excluded.upload_token,
            batch_id = excluded.batch_id,
            retry_count = excluded.retry_count,
            last_error = excluded.last_error,
            last_known_uploaded_bytes = excluded.last_known_uploaded_bytes,
            session_expiration_count = excluded.session_expiration_count,
            updated_at = excluded.updated_at
        WHERE '.self::TABLE_NAME.'.state = \'Pending\'';

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

    public function saveBatch(array $jobs): int
    {
        if ([] === $jobs) {
            return 0;
        }

        // Use transaction for atomicity - all jobs are saved or none
        // Batch INSERT with multiple VALUES for better performance
        $this->connection->beginTransaction();

        try {
            // Build batch INSERT with multiple VALUES
            $placeholders = [];
            $params = [];
            $savedCount = 0;

            foreach ($jobs as $job) {
                $data = $this->prepareJobData($job);
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $params[] = $data['id'];
                $params[] = $data['file_path'];
                $params[] = $data['file_size'];
                $params[] = $data['file_hash'];
                $params[] = $data['mime_type'];
                $params[] = $data['is_video'];
                $params[] = $data['state'];
                $params[] = $data['resumable_session_uri'];
                $params[] = $data['uploaded_bytes'];
                $params[] = $data['upload_token'];
                $params[] = $data['batch_id'];
                $params[] = $data['creation_time'];
                $params[] = $data['retry_count'];
                $params[] = $data['last_error'];
                $params[] = $data['last_known_uploaded_bytes'];
                $params[] = $data['session_expiration_count'];
                $params[] = $data['created_at'];
                $params[] = $data['updated_at'];
                ++$savedCount;
            }

            $sql = 'INSERT INTO '.self::TABLE_NAME.' (
                id, file_path, file_size, file_hash, mime_type, is_video, state,
                resumable_session_uri, uploaded_bytes, upload_token, batch_id,
                creation_time, retry_count, last_error, last_known_uploaded_bytes,
                session_expiration_count, created_at, updated_at
            ) VALUES '.\implode(', ', $placeholders).'
            ON CONFLICT(id) DO UPDATE SET
                file_path = excluded.file_path,
                file_size = excluded.file_size,
                file_hash = excluded.file_hash,
                mime_type = excluded.mime_type,
                is_video = excluded.is_video,
                resumable_session_uri = excluded.resumable_session_uri,
                uploaded_bytes = excluded.uploaded_bytes,
                upload_token = excluded.upload_token,
                batch_id = excluded.batch_id,
                retry_count = excluded.retry_count,
                last_error = excluded.last_error,
                last_known_uploaded_bytes = excluded.last_known_uploaded_bytes,
                session_expiration_count = excluded.session_expiration_count,
                updated_at = excluded.updated_at
            WHERE '.self::TABLE_NAME.'.state = \'Pending\'';

            $this->connection->executeStatement($sql, $params);
            $this->connection->commit();

            return $savedCount;
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Prepare job data array for database operations.
     *
     * @return array<string, mixed>
     */
    private function prepareJobData(UploadJob $job): array
    {
        return [
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
