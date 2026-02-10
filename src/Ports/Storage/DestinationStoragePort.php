<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage;

use SortingPhotosByDate\Domain\Storage\StorageItem;
use SortingPhotosByDate\Domain\Storage\StorageMetadata;

/**
 * Port for writing to storage (destination).
 */
interface DestinationStoragePort
{
    /**
     * Write item content to storage.
     *
     * @param StorageItem     $item     Storage item to write
     * @param string          $content  File content
     * @param StorageMetadata $metadata Item metadata (optional, may be ignored by some storages)
     *
     * @return bool True on success
     *
     * @throws \RuntimeException If writing fails
     */
    public function write(StorageItem $item, string $content, StorageMetadata $metadata): bool;

    /**
     * Check if item exists in storage.
     *
     * @param StorageItem $item Storage item to check
     *
     * @return bool True if exists
     */
    public function exists(StorageItem $item): bool;

    /**
     * Delete item from storage.
     *
     * @param StorageItem $item Storage item to delete
     *
     * @return bool True on success
     *
     * @throws \RuntimeException If deletion fails
     */
    public function delete(StorageItem $item): bool;

    /**
     * Ensure directory/path exists in storage.
     *
     * @param string $path Directory path
     *
     * @return bool True on success
     *
     * @throws \RuntimeException If directory creation fails
     */
    public function ensureDirectory(string $path): bool;

    /**
     * Get storage type.
     */
    public function getStorageType(): \SortingPhotosByDate\Domain\Storage\StorageType;
}
