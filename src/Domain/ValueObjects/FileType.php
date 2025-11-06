<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\ValueObjects;

enum FileType: string
{
    case IMAGE = 'image';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case DOCUMENT = 'document';
    case OTHER = 'other';

    public function toCategory(): FileCategory
    {
        return match ($this) {
            self::IMAGE => FileCategory::IMAGES,
            self::VIDEO => FileCategory::VIDEO,
            self::AUDIO => FileCategory::AUDIO,
            self::DOCUMENT, self::OTHER => FileCategory::OTHER,
        };
    }

    /**
     * Determine file type from MIME type.
     */
    public static function fromMimeType(string $mimeType): self
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => self::IMAGE,
            str_starts_with($mimeType, 'video/') => self::VIDEO,
            str_starts_with($mimeType, 'audio/') => self::AUDIO,
            str_starts_with($mimeType, 'application/pdf'),
            str_starts_with($mimeType, 'application/msword'),
            str_starts_with($mimeType, 'application/vnd.ms-excel'),
            str_starts_with($mimeType, 'application/vnd.ms-powerpoint'),
            str_starts_with($mimeType, 'application/vnd.openxmlformats') => self::DOCUMENT,
            default => self::OTHER,
        };
    }

    /**
     * Determine file type from file extension.
     * Used as fallback when MIME type detection fails.
     */
    public static function fromExtension(string $filePath): self
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            // Image formats
            'jpg', 'jpeg', 'jpe', 'jfif', 'jif', 'jfi',
            'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'heic', 'heif',
            'tiff', 'tif', 'psd', 'raw', 'cr2', 'nef', 'orf', 'sr2',
            'arw', 'dng', 'rw2', 'raf', '3fr', 'dcr', 'kdc', 'mef',
            'mos', 'mrw', 'pef', 'srw', 'x3f' => self::IMAGE,

            // Video formats
            'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', 'm4v',
            'mpg', 'mpeg', '3gp', '3g2', 'asf', 'rm', 'rmvb', 'vob',
            'ogv', 'divx', 'xvid', 'mts', 'm2ts', 'ts', 'f4v', 'swf' => self::VIDEO,

            // Audio formats
            'mp3', 'wav', 'flac', 'aac', 'ogg', 'oga', 'm4a', 'wma',
            'opus', 'amr', '3ga', 'aiff', 'au', 'ra', 'ac3', 'dts',
            'ape', 'tta', 'tak', 'wv', 'mpc', 'spx', 'gsm', 'voc' => self::AUDIO,

            // Document formats
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt',
            'ods', 'odp', 'rtf', 'txt', 'csv', 'pages', 'numbers', 'key',
            'epub', 'mobi', 'azw', 'fb2', 'djvu', 'xps' => self::DOCUMENT,

            default => self::OTHER,
        };
    }

    /**
     * Smart file type detection using multiple sources:
     * 1. MIME type (if valid and not generic)
     * 2. File extension (as fallback)
     * 3. EXIF data check (for images)
     * 4. Metadata hints (if available)
     *
     * @param string                    $filePath File path
     * @param string                    $mimeType Detected MIME type
     * @param bool|null                 $hasExif  Whether file has EXIF data (null if unknown)
     * @param array<string, mixed>|null $metadata Additional metadata hints (e.g., ['width' => 1920, 'height' => 1080])
     */
    public static function detectSmart(
        string $filePath,
        string $mimeType,
        ?bool $hasExif = null,
        ?array $metadata = null,
    ): self {
        // If MIME type is valid and not generic, use it
        if ('application/octet-stream' !== $mimeType && '' !== $mimeType) {
            $typeFromMime = self::fromMimeType($mimeType);
            // If MIME type gives us a specific type (not OTHER), use it
            if (self::OTHER !== $typeFromMime) {
                return $typeFromMime;
            }
        }

        // If file has EXIF data, it's definitely an image
        if (true === $hasExif) {
            return self::IMAGE;
        }

        // Check metadata hints (width/height suggests image or video)
        if (null !== $metadata) {
            $hasWidth = isset($metadata['width']) && $metadata['width'] > 0;
            $hasHeight = isset($metadata['height']) && $metadata['height'] > 0;
            $hasDuration = isset($metadata['duration']) && $metadata['duration'] > 0;

            if ($hasDuration) {
                return self::VIDEO;
            }

            if ($hasWidth && $hasHeight) {
                // If MIME type suggests video, prefer video
                if (str_starts_with($mimeType, 'video/')) {
                    return self::VIDEO;
                }

                // Otherwise assume image
                return self::IMAGE;
            }
        }

        // Fallback to extension-based detection
        return self::fromExtension($filePath);
    }
}
