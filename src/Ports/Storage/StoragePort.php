<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage;

use SortingPhotosByDate\Domain\Storage\StorageItem;
use SortingPhotosByDate\Domain\Storage\StorageMetadata;

/**
 * Port for reading from storage (source).
 */
interface StoragePort
{
    /**
     * Scan storage location and return items.
     *
     * @param string $location Storage location/path to scan
     *
     * @return iterable<StorageItem> List of storage items
     *
     * @throws \RuntimeException If scanning fails
     */
    public function scan(string $location): iterable;

    /**
     * Read item content from storage.
     *
     * @param StorageItem $item Storage item to read
     *
     * @return string File content
     *
     * @throws \RuntimeException If reading fails
     */
    public function read(StorageItem $item): string;

    /**
     * Get item metadata from storage.
     *
     * @param StorageItem $item Storage item
     *
     * @return StorageMetadata Item metadata
     *
     * @throws \RuntimeException If metadata retrieval fails
     */
    public function getMetadata(StorageItem $item): StorageMetadata;

    /**
     * Check if item exists in storage.
     *
     * @param StorageItem $item Storage item to check
     *
     * @return bool True if exists
     */
    public function exists(StorageItem $item): bool;

    /**
     * Get storage type.
     */
    public function getStorageType(): \SortingPhotosByDate\Domain\Storage\StorageType;
}
