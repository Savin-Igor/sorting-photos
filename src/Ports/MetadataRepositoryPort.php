<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports;

use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Port for storing and retrieving file metadata.
 */
interface MetadataRepositoryPort
{
    /**
     * Save metadata for a file.
     *
     * @param MediaAsset $asset Media asset to save
     *
     * @return bool True on success
     */
    public function save(MediaAsset $asset): bool;

    /**
     * Find asset by file path, size and hash (for idempotency check).
     *
     * @param FilePath $filePath File path
     * @param int      $fileSize File size in bytes
     * @param FileHash $hash     File hash
     *
     * @return MediaAsset|null Found asset or null
     */
    public function findByPathSizeAndHash(FilePath $filePath, int $fileSize, FileHash $hash): ?MediaAsset;

    /**
     * Find asset by hash (for deduplication).
     *
     * @param FileHash $hash File hash
     *
     * @return MediaAsset|null Found asset or null
     */
    public function findByHash(FileHash $hash): ?MediaAsset;

    /**
     * Check if file was already processed (idempotency check).
     *
     * @param FilePath $filePath File path
     * @param int      $fileSize File size in bytes
     * @param FileHash $hash     File hash
     *
     * @return bool True if already processed
     */
    public function isProcessed(FilePath $filePath, int $fileSize, FileHash $hash): bool;

    /**
     * Delete metadata for a file.
     *
     * @param FilePath $filePath File path
     *
     * @return bool True on success
     */
    public function delete(FilePath $filePath): bool;
}
