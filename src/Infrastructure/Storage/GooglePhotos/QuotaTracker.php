<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use Doctrine\DBAL\Connection;

final readonly class QuotaTracker
{
    private const string TABLE_NAME = 'google_photos_quota_status';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function getStatus(): QuotaStatus
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE_NAME.' ORDER BY date DESC LIMIT 1'
        );

        if (false === $row) {
            // Initialize new day
            return $this->initializeNewDay();
        }

        $dateStr = \is_string($row['date']) ? $row['date'] : '';
        $date = '' !== $dateStr ? \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr) : false;
        $today = new \DateTimeImmutable('today');

        // If not today, reset counters
        if (false === $date || $date->format('Y-m-d') !== $today->format('Y-m-d')) {
            return $this->initializeNewDay();
        }

        return new QuotaStatus(
            requestsUsed: \is_numeric($row['requests_used']) ? (int) $row['requests_used'] : 0,
            requestsLimit: \is_numeric($row['requests_limit']) ? (int) $row['requests_limit'] : 10_000,
            bytesUsed: \is_numeric($row['bytes_used']) ? (int) $row['bytes_used'] : 0,
            bytesLimit: \is_numeric($row['bytes_limit']) ? (int) $row['bytes_limit'] : 75_000_000_000,
            resetTime: $today->modify('+1 day')->setTime(0, 0, 0),
        );
    }

    public function recordRequest(): void
    {
        $today = new \DateTimeImmutable('today');
        $dateStr = $today->format('Y-m-d');

        // Check if record exists
        $existing = $this->connection->fetchOne(
            'SELECT date FROM '.self::TABLE_NAME.' WHERE date = ?',
            [$dateStr]
        );

        if (false !== $existing) {
            $this->connection->executeStatement(
                'UPDATE '.self::TABLE_NAME.' SET requests_used = requests_used + 1, updated_at = ? WHERE date = ?',
                [new \DateTimeImmutable()->format('Y-m-d H:i:s'), $dateStr]
            );
        } else {
            $this->connection->insert(self::TABLE_NAME, [
                'date' => $dateStr,
                'requests_used' => 1,
                'requests_limit' => 10_000,
                'bytes_used' => 0,
                'bytes_limit' => 75_000_000_000,
                'updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ]);
        }
    }

    public function recordBytesUploaded(int $bytes): void
    {
        $today = new \DateTimeImmutable('today');
        $dateStr = $today->format('Y-m-d');

        // Check if record exists
        $existing = $this->connection->fetchOne(
            'SELECT date FROM '.self::TABLE_NAME.' WHERE date = ?',
            [$dateStr]
        );

        if (false !== $existing) {
            $this->connection->executeStatement(
                'UPDATE '.self::TABLE_NAME.' SET bytes_used = bytes_used + ?, updated_at = ? WHERE date = ?',
                [$bytes, new \DateTimeImmutable()->format('Y-m-d H:i:s'), $dateStr]
            );
        } else {
            $this->connection->insert(self::TABLE_NAME, [
                'date' => $dateStr,
                'requests_used' => 0,
                'requests_limit' => 10_000,
                'bytes_used' => $bytes,
                'bytes_limit' => 75_000_000_000,
                'updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function initializeNewDay(): QuotaStatus
    {
        $today = new \DateTimeImmutable('today');
        $dateStr = $today->format('Y-m-d');

        // Check if record exists
        $existing = $this->connection->fetchOne(
            'SELECT date FROM '.self::TABLE_NAME.' WHERE date = ?',
            [$dateStr]
        );

        if (false === $existing) {
            $this->connection->insert(self::TABLE_NAME, [
                'date' => $dateStr,
                'requests_used' => 0,
                'requests_limit' => 10_000,
                'bytes_used' => 0,
                'bytes_limit' => 75_000_000_000,
                'updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ]);
        }

        return new QuotaStatus(
            requestsUsed: 0,
            requestsLimit: 10_000,
            bytesUsed: 0,
            bytesLimit: 75_000_000_000,
            resetTime: $today->modify('+1 day')->setTime(0, 0, 0),
        );
    }
}
