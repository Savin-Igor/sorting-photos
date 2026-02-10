<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\QuotaTracker;
use SortingPhotosByDate\Ports\LoggerPort;

final readonly class QuotaManager
{
    private const int REQUESTS_LIMIT = 10_000;
    private const int BYTES_LIMIT = 75_000_000_000; // 75 GB in bytes (approximately)

    public function __construct(
        private QuotaTracker $quotaTracker,
        private LoggerPort $logger,
    ) {
    }

    public function checkQuota(): void
    {
        $status = $this->quotaTracker->getStatus();

        // Check request limit
        if ($status->getRequestsUsed() >= self::REQUESTS_LIMIT) {
            $this->logger->warning('Request quota exceeded', [
                'used' => $status->getRequestsUsed(),
                'limit' => self::REQUESTS_LIMIT,
                'reset_time' => $status->getResetTime()->format('Y-m-d H:i:s'),
            ]);

            throw QuotaExceededException::requestsExceeded($status->getResetTime());
        }

        // Check bytes limit
        if ($status->getBytesUsed() >= self::BYTES_LIMIT) {
            $this->logger->warning('Bytes quota exceeded', [
                'used' => $status->getBytesUsed(),
                'limit' => self::BYTES_LIMIT,
                'reset_time' => $status->getResetTime()->format('Y-m-d H:i:s'),
            ]);

            throw QuotaExceededException::bytesExceeded($status->getResetTime());
        }
    }

    public function recordRequest(): void
    {
        $this->quotaTracker->recordRequest();
    }

    public function recordBytesUploaded(int $bytes): void
    {
        $this->quotaTracker->recordBytesUploaded($bytes);
    }

    public function isQuotaAvailable(): bool
    {
        $status = $this->quotaTracker->getStatus();

        return $status->getRequestsUsed() < self::REQUESTS_LIMIT
            && $status->getBytesUsed() < self::BYTES_LIMIT;
    }

    public function getResetTime(): \DateTimeImmutable
    {
        return $this->quotaTracker->getStatus()->getResetTime();
    }
}
