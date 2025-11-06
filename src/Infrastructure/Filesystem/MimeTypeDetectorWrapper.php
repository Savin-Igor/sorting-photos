<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Filesystem;

use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;

/**
 * Wrapper around League MIME Type Detection library.
 * Provides a clean interface for MIME type detection.
 */
final class MimeTypeDetectorWrapper
{
    private readonly MimeTypeDetector $detector;

    public function __construct(?MimeTypeDetector $detector = null)
    {
        $this->detector = $detector ?? new FinfoMimeTypeDetector();
    }

    /**
     * Detect MIME type for a file.
     *
     * @param string $filePath Path to the file
     *
     * @return string MIME type or 'application/octet-stream' if detection fails
     */
    public function detectMimeType(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return 'application/octet-stream';
        }

        $mimeType = $this->detector->detectMimeTypeFromFile($filePath);

        return $mimeType ?? 'application/octet-stream';
    }

    /**
     * Detect MIME type from file contents.
     *
     * @param string $contents File contents
     *
     * @return string MIME type or 'application/octet-stream' if detection fails
     */
    public function detectMimeTypeFromBuffer(string $contents): string
    {
        $mimeType = $this->detector->detectMimeTypeFromBuffer($contents);

        return $mimeType ?? 'application/octet-stream';
    }
}
