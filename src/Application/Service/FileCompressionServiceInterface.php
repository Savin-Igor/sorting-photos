<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service;

use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Interface for file compression service.
 */
interface FileCompressionServiceInterface
{
    /**
     * Compress file if needed based on MediaAsset metadata.
     *
     * @param FilePath   $filePath Path to the file to compress
     * @param MediaAsset $asset    Media asset with metadata
     *
     * @return FilePath Path to compressed file (or original if no compression needed)
     */
    public function compressIfNeeded(FilePath $filePath, MediaAsset $asset): FilePath;

    /**
     * Check if compressed file is temporary and should be cleaned up.
     *
     * @param FilePath $filePath File path to check
     *
     * @return bool True if file is temporary and should be cleaned up
     */
    public function isTemporaryFile(FilePath $filePath): bool;
}
