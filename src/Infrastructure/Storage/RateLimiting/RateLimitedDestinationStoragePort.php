<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\RateLimiting;

use SortingPhotosByDate\Domain\Storage\StorageItem;
use SortingPhotosByDate\Domain\Storage\StorageMetadata;
use SortingPhotosByDate\Domain\Storage\StorageType;
use SortingPhotosByDate\Ports\Storage\DestinationStoragePort;

/**
 * Decorator that adds rate limiting to DestinationStoragePort.
 */
final readonly class RateLimitedDestinationStoragePort implements DestinationStoragePort
{
    public function __construct(
        private DestinationStoragePort $inner,
        private RateLimiter $rateLimiter,
    ) {
    }

    public function write(StorageItem $item, string $content, StorageMetadata $metadata): bool
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->write($item, $content, $metadata);
    }

    public function exists(StorageItem $item): bool
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->exists($item);
    }

    public function delete(StorageItem $item): bool
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->delete($item);
    }

    public function ensureDirectory(string $path): bool
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->ensureDirectory($path);
    }

    public function getStorageType(): StorageType
    {
        return $this->inner->getStorageType();
    }
}
