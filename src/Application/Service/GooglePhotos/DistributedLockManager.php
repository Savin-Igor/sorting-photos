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

    /**
     * Check if process holding the lock is still alive.
     */
    public function isLockOwnerAlive(string $lockName): bool
    {
        $row = $this->connection->fetchAssociative(
            'SELECT process_id FROM '.self::TABLE_NAME.' WHERE lock_name = ?',
            [$lockName]
        );

        if (false === $row) {
            return false; // No lock exists
        }

        $processId = (string) $row['process_id'];

        // Check if process exists (Linux/Unix)
        if (\function_exists('posix_kill')) {
            return \posix_kill((int) $processId, 0); // Signal 0 just checks if process exists
        }

        // Fallback: check if process exists using ps (Linux)
        if (\PHP_OS_FAMILY === 'Linux') {
            $output = [];
            $returnCode = 0;
            @\exec(\sprintf('ps -p %s > /dev/null 2>&1', \escapeshellarg($processId)), $output, $returnCode);

            return 0 === $returnCode;
        }

        // Windows: check using tasklist
        if (\PHP_OS_FAMILY === 'Windows') {
            $output = [];
            $returnCode = 0;
            @\exec(\sprintf('tasklist /FI "PID eq %s" 2>NUL | find /I "%s"', $processId, $processId), $output, $returnCode);

            return 0 === $returnCode;
        }

        // Unknown OS - assume process is alive (conservative approach)
        return true;
    }

    /**
     * Get lock information.
     *
     * @return array{process_id: string, acquired_at: string, expires_at: string}|null
     */
    public function getLockInfo(string $lockName): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT process_id, acquired_at, expires_at FROM '.self::TABLE_NAME.' WHERE lock_name = ?',
            [$lockName]
        );

        if (false === $row) {
            return null;
        }

        return [
            'process_id' => (string) $row['process_id'],
            'acquired_at' => (string) $row['acquired_at'],
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    /**
     * Force release lock (for admin/debug purposes).
     */
    public function forceReleaseLock(string $lockName): bool
    {
        $affected = $this->connection->delete(
            self::TABLE_NAME,
            ['lock_name' => $lockName]
        );

        return $affected > 0;
    }
}
