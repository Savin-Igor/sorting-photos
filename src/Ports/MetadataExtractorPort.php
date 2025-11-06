<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports;

use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;

/**
 * Port for extracting metadata from files.
 */
interface MetadataExtractorPort
{
    /**
     * Extract metadata from file.
     *
     * @param string $filePath Path to the file
     *
     * @return MediaMeta Extracted metadata
     */
    public function extract(string $filePath): MediaMeta;

    /**
     * Extract date from file.
     *
     * @param string $filePath Path to the file
     *
     * @return MediaDate Extracted date
     */
    public function extractDate(string $filePath): MediaDate;

    /**
     * Check if this extractor supports the given MIME type.
     *
     * @param string $mimeType MIME type to check
     *
     * @return bool True if supported
     */
    public function supports(string $mimeType): bool;
}
