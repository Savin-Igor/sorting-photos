<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service;

use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\ImageCompressor;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\VideoCompressor;
use SortingPhotosByDate\Ports\LoggerPort;

/**
 * Unified service for file compression.
 * Handles compression of images and videos based on MediaAsset metadata.
 */
final readonly class FileCompressionService implements FileCompressionServiceInterface
{
    public function __construct(
        private ImageCompressor $imageCompressor,
        private VideoCompressor $videoCompressor,
        private LoggerPort $logger,
    ) {
    }

    /**
     * Compress file if needed based on MediaAsset metadata.
     *
     * @param FilePath   $filePath Path to the file to compress
     * @param MediaAsset $asset    Media asset with metadata
     *
     * @return FilePath Path to compressed file (or original if no compression needed)
     */
    public function compressIfNeeded(FilePath $filePath, MediaAsset $asset): FilePath
    {
        $mimeType = $asset->getMimeType();
        $originalPath = $filePath->getPath();

        // Handle images
        if (\str_starts_with($mimeType, 'image/')) {
            $compressedPath = $this->imageCompressor->compressIfNeeded($originalPath, $mimeType);

            if ($compressedPath !== $originalPath) {
                $this->logger->debug('Image compressed', [
                    'original_path' => $originalPath,
                    'compressed_path' => $compressedPath,
                    'mime_type' => $mimeType,
                ]);

                return new FilePath($compressedPath);
            }

            return $filePath;
        }

        // Handle videos
        if (\str_starts_with($mimeType, 'video/')) {
            $compressedPath = $this->videoCompressor->compressIfNeeded($originalPath);

            if ($compressedPath !== $originalPath) {
                $this->logger->debug('Video compressed', [
                    'original_path' => $originalPath,
                    'compressed_path' => $compressedPath,
                    'mime_type' => $mimeType,
                ]);

                return new FilePath($compressedPath);
            }

            return $filePath;
        }

        // Other file types - no compression
        return $filePath;
    }

    /**
     * Check if compressed file is temporary and should be cleaned up.
     *
     * @param FilePath $filePath File path to check
     *
     * @return bool True if file is temporary and should be cleaned up
     */
    public function isTemporaryFile(FilePath $filePath): bool
    {
        $path = $filePath->getPath();

        return \str_starts_with($path, \sys_get_temp_dir());
    }
}
