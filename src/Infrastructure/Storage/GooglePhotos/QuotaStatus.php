<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

final readonly class QuotaStatus
{
    public function __construct(
        private int $requestsUsed,
        private int $requestsLimit,
        private int $bytesUsed,
        private int $bytesLimit,
        private \DateTimeImmutable $resetTime,
    ) {
    }

    public function getRequestsUsed(): int
    {
        return $this->requestsUsed;
    }

    public function getRequestsLimit(): int
    {
        return $this->requestsLimit;
    }

    public function getBytesUsed(): int
    {
        return $this->bytesUsed;
    }

    public function getBytesLimit(): int
    {
        return $this->bytesLimit;
    }

    public function getResetTime(): \DateTimeImmutable
    {
        return $this->resetTime;
    }
}
