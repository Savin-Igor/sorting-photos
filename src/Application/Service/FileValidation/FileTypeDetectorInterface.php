<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\FileValidation;

/**
 * Interface for detecting file types (image/video/media).
 */
interface FileTypeDetectorInterface
{
    /**
     * Check if file is a video.
     *
     * @param string      $mimeType Detected MIME type
     * @param string|null $filePath Optional file path for extension-based fallback
     *
     * @return bool True if file is a video
     */
    public function isVideo(string $mimeType, ?string $filePath = null): bool;

    /**
     * Check if file is a media file supported by Google Photos API.
     * Google Photos only supports images and videos.
     *
     * @param string      $mimeType Detected MIME type
     * @param string|null $filePath Optional file path for extension-based fallback
     *
     * @return bool True if file is a media file
     */
    public function isMediaFile(string $mimeType, ?string $filePath = null): bool;

    /**
     * Get MIME type from file extension as fallback when MIME detection fails.
     *
     * @param string $filePath Full path to the file
     *
     * @return string|null MIME type or null if extension is not recognized
     */
    public function getMimeTypeFromExtension(string $filePath): ?string;
}
