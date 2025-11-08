<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use SortingPhotosByDate\Ports\LoggerPort;

final readonly class DistributedLockManager
{
    private const string TABLE_NAME = 'distributed_locks';

    public function __construct(
        private Connection $connection,
        private LoggerPort $logger,
    ) {
    }

    public function acquireLock(string $lockName, int $ttlSeconds): bool
    {
        $processId = (string) \getmypid();
        $now = new \DateTimeImmutable();
        $expiresAt = $now->modify(\sprintf('+%d seconds', $ttlSeconds));

        try {
            $this->connection->insert(self::TABLE_NAME, [
                'lock_name' => $lockName,
                'process_id' => $processId,
                'acquired_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ]);

            $this->logger->debug('Lock acquired', [
                'lock_name' => $lockName,
                'process_id' => $processId,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // Lock already acquired
            $this->logger->debug('Lock already acquired by another process', [
                'lock_name' => $lockName,
            ]);

            return false;
        }
    }

    public function refreshLock(string $lockName): void
    {
        $processId = (string) \getmypid();
        $now = new \DateTimeImmutable();

        $affected = $this->connection->update(
            self::TABLE_NAME,
            [
                'acquired_at' => $now->format('Y-m-d H:i:s'),
            ],
            [
                'lock_name' => $lockName,
                'process_id' => $processId,
            ]
        );

        if (0 === $affected) {
            $this->logger->warning('Failed to refresh lock - lock may have been released', [
                'lock_name' => $lockName,
                'process_id' => $processId,
            ]);
        }
    }

    public function releaseLock(string $lockName): void
    {
        $processId = (string) \getmypid();

        $affected = $this->connection->delete(
            self::TABLE_NAME,
            [
                'lock_name' => $lockName,
                'process_id' => $processId,
            ]
        );

        if ($affected > 0) {
            $this->logger->debug('Lock released', [
                'lock_name' => $lockName,
                'process_id' => $processId,
            ]);
        }
    }

    public function cleanupExpiredLocks(): void
    {
        $now = new \DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $affected = $this->connection->executeStatement(
            'DELETE FROM '.self::TABLE_NAME.' WHERE expires_at < ?',
            [$nowStr]
        );

        if ($affected > 0) {
            $this->logger->debug('Expired locks cleaned up', [
                'count' => $affected,
            ]);
        }
    }
}
