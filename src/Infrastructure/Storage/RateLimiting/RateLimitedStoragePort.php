<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\RateLimiting;

use SortingPhotosByDate\Domain\Storage\StorageItem;
use SortingPhotosByDate\Domain\Storage\StorageMetadata;
use SortingPhotosByDate\Domain\Storage\StorageType;
use SortingPhotosByDate\Ports\Storage\StoragePort;

/**
 * Decorator that adds rate limiting to StoragePort.
 */
final readonly class RateLimitedStoragePort implements StoragePort
{
    public function __construct(
        private StoragePort $inner,
        private RateLimiter $rateLimiter,
    ) {
    }

    public function scan(string $location): iterable
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->scan($location);
    }

    public function read(StorageItem $item): string
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->read($item);
    }

    public function getMetadata(StorageItem $item): StorageMetadata
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->getMetadata($item);
    }

    public function exists(StorageItem $item): bool
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->exists($item);
    }

    public function getStorageType(): StorageType
    {
        return $this->inner->getStorageType();
    }
}
