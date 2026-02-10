<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Filesystem;

use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\MimeTypeDetectorInterface;

/**
 * Adapter wrapper for League MIME Type Detection library.
 * Implements MimeTypeDetectorInterface using vendor library.
 */
final readonly class MimeTypeDetectorAdapter implements MimeTypeDetectorInterface
{
    public function __construct(private ?MimeTypeDetector $detector = new FinfoMimeTypeDetector(), private ?FilesystemPort $filesystem = null)
    {
    }

    #[\Override]
    public function detectMimeType(string $filePath): string
    {
        // Use FilesystemPort if available, otherwise fallback to native check
        if ($this->filesystem instanceof FilesystemPort) {
            $filePathObj = new \SortingPhotosByDate\Domain\ValueObjects\FilePath($filePath);
            if (!$this->filesystem->exists($filePathObj)) {
                return 'application/octet-stream';
            }
        } elseif (!file_exists($filePath)) {
            return 'application/octet-stream';
        }

        if (!$this->detector instanceof MimeTypeDetector) {
            return 'application/octet-stream';
        }

        $mimeType = $this->detector->detectMimeTypeFromFile($filePath);

        // Fallbacks for when detector returns null or generic mime
        if (null === $mimeType || 'application/octet-stream' === $mimeType) {
            // 1) Try exif_imagetype for images (more reliable for JPEG/PNG/etc.)
            if (\function_exists('exif_imagetype')) {
                /** @var int|false $imgType */
                $imgType = @\exif_imagetype($filePath);
                if (false !== $imgType) {
                    return match ($imgType) {
                        \IMAGETYPE_JPEG => 'image/jpeg',
                        \IMAGETYPE_PNG => 'image/png',
                        \IMAGETYPE_GIF => 'image/gif',
                        \IMAGETYPE_BMP => 'image/bmp',
                        \IMAGETYPE_WEBP => 'image/webp',
                        \IMAGETYPE_TIFF_II, \IMAGETYPE_TIFF_MM => 'image/tiff',
                        default => 'application/octet-stream',
                    };
                }
            }

            // 2) Extension-based heuristic fallback
            $ext = \strtolower(\pathinfo($filePath, \PATHINFO_EXTENSION));
            $byExt = [
                // Images
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'bmp' => 'image/bmp',
                'tif' => 'image/tiff',
                'tiff' => 'image/tiff',
                'heif' => 'image/heif',
                'heic' => 'image/heic',
                // Video
                'mp4' => 'video/mp4',
                'mov' => 'video/quicktime',
                'mkv' => 'video/x-matroska',
                'webm' => 'video/webm',
                'avi' => 'video/x-msvideo',
                'wmv' => 'video/x-ms-wmv',
                // Audio
                'mp3' => 'audio/mpeg',
                'flac' => 'audio/flac',
                'wav' => 'audio/wav',
                'ogg' => 'audio/ogg',
                'aac' => 'audio/aac',
                'wma' => 'audio/x-ms-wma',
            ];
            if (isset($byExt[$ext])) {
                return $byExt[$ext];
            }
        }

        return $mimeType ?? 'application/octet-stream';
    }

    #[\Override]
    public function detectMimeTypeFromBuffer(string $contents): string
    {
        if (!$this->detector instanceof MimeTypeDetector) {
            return 'application/octet-stream';
        }

        $mimeType = $this->detector->detectMimeTypeFromBuffer($contents);

        return $mimeType ?? 'application/octet-stream';
    }
}
