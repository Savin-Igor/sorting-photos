<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\FileValidation;

/**
 * Detects file types (image/video/media) using MIME type and extension fallback.
 */
final readonly class FileTypeDetector implements FileTypeDetectorInterface
{
    /**
     * Video file extensions supported by Google Photos.
     */
    private const array VIDEO_EXTENSIONS = [
        'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', 'm4v',
        'mpg', 'mpeg', '3gp', '3g2', 'asf', 'rm', 'rmvb', 'vob',
        'ogv', 'divx', 'xvid', 'mts', 'm2ts', 'ts', 'f4v',
    ];

    /**
     * Image formats supported by Google Photos.
     */
    private const array IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'jpe', 'jfif', 'jif', 'jfi',
        'png', 'gif', 'bmp', 'webp', 'heic', 'heif',
        'tiff', 'tif', 'raw', 'cr2', 'nef', 'orf', 'sr2',
        'arw', 'dng', 'rw2', 'raf', '3fr', 'dcr', 'kdc', 'mef',
        'mos', 'mrw', 'pef', 'srw', 'x3f',
    ];

    /**
     * MIME type mapping from file extension.
     */
    private const array MIME_TYPE_MAP = [
        // Images
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpe' => 'image/jpeg',
        'jfif' => 'image/jpeg',
        'jif' => 'image/jpeg',
        'jfi' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        'heic' => 'image/heif',
        'heif' => 'image/heif',
        'tiff' => 'image/tiff',
        'tif' => 'image/tiff',
        'raw' => 'image/x-raw',
        'cr2' => 'image/x-canon-cr2',
        'nef' => 'image/x-nikon-nef',
        'orf' => 'image/x-olympus-orf',
        'sr2' => 'image/x-sony-sr2',
        'arw' => 'image/x-sony-arw',
        'dng' => 'image/x-adobe-dng',
        'rw2' => 'image/x-panasonic-rw2',
        'raf' => 'image/x-fuji-raf',
        '3fr' => 'image/x-hasselblad-3fr',
        'dcr' => 'image/x-kodak-dcr',
        'kdc' => 'image/x-kodak-kdc',
        'mef' => 'image/x-mamiya-mef',
        'mos' => 'image/x-creative-mos',
        'mrw' => 'image/x-minolta-mrw',
        'pef' => 'image/x-pentax-pef',
        'srw' => 'image/x-samsung-srw',
        'x3f' => 'image/x-sigma-x3f',
        // Videos
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
        'wmv' => 'video/x-ms-wmv',
        'flv' => 'video/x-flv',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
        'm4v' => 'video/x-m4v',
        'mpg' => 'video/mpeg',
        'mpeg' => 'video/mpeg',
        '3gp' => 'video/3gpp',
        '3g2' => 'video/3gpp2',
        'asf' => 'video/x-ms-asf',
        'rm' => 'video/vnd.rn-realvideo',
        'rmvb' => 'video/vnd.rn-realvideo',
        'vob' => 'video/dvd',
        'ogv' => 'video/ogg',
        'divx' => 'video/x-divx',
        'xvid' => 'video/x-xvid',
        'mts' => 'video/mp2t',
        'm2ts' => 'video/mp2t',
        'ts' => 'video/mp2t',
        'f4v' => 'video/x-f4v',
    ];

    public function isVideo(string $mimeType, ?string $filePath = null): bool
    {
        // Primary check: MIME type
        if (\str_starts_with($mimeType, 'video/')) {
            return true;
        }

        // Fallback: if MIME type is generic, check extension
        if (('application/octet-stream' === $mimeType || '' === $mimeType) && null !== $filePath) {
            $extension = \strtolower(\pathinfo($filePath, \PATHINFO_EXTENSION));

            return \in_array($extension, self::VIDEO_EXTENSIONS, true);
        }

        return false;
    }

    public function isMediaFile(string $mimeType, ?string $filePath = null): bool
    {
        // Primary check: MIME type
        if (\str_starts_with($mimeType, 'image/') || \str_starts_with($mimeType, 'video/')) {
            return true;
        }

        // Fallback: if MIME type is generic (application/octet-stream) or unknown,
        // check file extension to determine if it's a media file
        if (('application/octet-stream' === $mimeType || '' === $mimeType) && null !== $filePath) {
            return $this->isMediaExtension($filePath);
        }

        return false;
    }

    public function getMimeTypeFromExtension(string $filePath): ?string
    {
        $extension = \strtolower(\pathinfo($filePath, \PATHINFO_EXTENSION));

        return self::MIME_TYPE_MAP[$extension] ?? null;
    }

    /**
     * Check if file extension indicates a media file (image or video).
     * Used as fallback when MIME type detection fails.
     *
     * @param string $filePath Full path to the file
     *
     * @return bool True if extension indicates a media file
     */
    private function isMediaExtension(string $filePath): bool
    {
        $extension = \strtolower(\pathinfo($filePath, \PATHINFO_EXTENSION));

        return \in_array($extension, \array_merge(self::IMAGE_EXTENSIONS, self::VIDEO_EXTENSIONS), true);
    }
}
