<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports;

/**
 * Port for MIME type detection.
 * Abstracts away vendor library (League MIME Type Detection).
 */
interface MimeTypeDetectorInterface
{
    /**
     * Detect MIME type for a file.
     *
     * @param string $filePath Path to the file
     *
     * @return string MIME type or 'application/octet-stream' if detection fails
     */
    public function detectMimeType(string $filePath): string;

    /**
     * Detect MIME type from file contents.
     *
     * @param string $contents File contents
     *
     * @return string MIME type or 'application/octet-stream' if detection fails
     */
    public function detectMimeTypeFromBuffer(string $contents): string;
}
