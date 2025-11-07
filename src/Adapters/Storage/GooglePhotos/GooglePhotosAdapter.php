<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Storage\GooglePhotos;

use SortingPhotosByDate\Domain\Storage\StorageItem;
use SortingPhotosByDate\Domain\Storage\StorageMetadata;
use SortingPhotosByDate\Domain\Storage\StorageType;
use SortingPhotosByDate\Infrastructure\Storage\Attribute\RateLimit;
use SortingPhotosByDate\Infrastructure\Storage\Attribute\StorageAdapter;
use SortingPhotosByDate\Ports\Storage\DestinationStoragePort;
use SortingPhotosByDate\Ports\Storage\StoragePort;

/**
 * Google Photos storage adapter.
 * TODO: Implement actual Google Photos Library API integration.
 */
#[StorageAdapter(type: StorageType::GOOGLE_PHOTOS)]
#[RateLimit(requests: 100, perSeconds: 100)]
final readonly class GooglePhotosAdapter implements StoragePort, DestinationStoragePort
{
    /**
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $options
     */
    public function __construct(
        private array $credentials,
        /** @phpstan-ignore-next-line Property is for future use when implementing Google Photos API */
        private array $options = [],
    ) {
        if (!isset($this->credentials['client_id']) || !isset($this->credentials['client_secret'])) {
            throw new \InvalidArgumentException('Google Photos adapter requires client_id and client_secret');
        }
    }

    public function scan(string $location): iterable
    {
        // TODO: Implement Google Photos API scanning
        // This would use Google Photos Library API to list media items
        throw new \RuntimeException('Google Photos adapter scan() not yet implemented');
    }

    public function read(StorageItem $item): string
    {
        // TODO: Implement Google Photos API reading
        // This would download media item from Google Photos
        throw new \RuntimeException('Google Photos adapter read() not yet implemented');
    }

    public function getMetadata(StorageItem $item): StorageMetadata
    {
        // TODO: Implement Google Photos API metadata retrieval
        throw new \RuntimeException('Google Photos adapter getMetadata() not yet implemented');
    }

    public function exists(StorageItem $item): bool
    {
        // TODO: Implement Google Photos API existence check
        throw new \RuntimeException('Google Photos adapter exists() not yet implemented');
    }

    public function write(StorageItem $item, string $content, StorageMetadata $metadata): bool
    {
        // TODO: Implement Google Photos API upload
        // This would upload media item to Google Photos
        throw new \RuntimeException('Google Photos adapter write() not yet implemented');
    }

    public function delete(StorageItem $item): bool
    {
        // TODO: Implement Google Photos API deletion
        throw new \RuntimeException('Google Photos adapter delete() not yet implemented');
    }

    public function ensureDirectory(string $path): bool
    {
        // Google Photos doesn't have directories, but we can create albums
        // TODO: Implement album creation if needed
        return true;
    }

    public function getStorageType(): StorageType
    {
        return StorageType::GOOGLE_PHOTOS;
    }
}
