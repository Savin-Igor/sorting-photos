<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use SortingPhotosByDate\Ports\LoggerPort;

final readonly class ImageCompressor
{
    public function __construct(
        private bool $enabled,
        private int $jpegMaxPixels,
        private int $pngMaxPixels,
        private int $minFileSizeBytes,
        private LoggerPort $logger,
    ) {
    }

    public function compressIfNeeded(string $filePath, string $mimeType): string
    {
        // If compression is disabled, return original file
        if (!$this->enabled) {
            return $filePath;
        }

        // Check file size first - don't compress small files
        $fileSize = \filesize($filePath);
        if (false === $fileSize || $fileSize < $this->minFileSizeBytes) {
            return $filePath; // File too small or failed to get size, skip compression
        }

        $imageInfo = \getimagesize($filePath);
        if (false === $imageInfo) {
            return $filePath; // Failed to determine size, keep as is
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $pixels = $width * $height;

        // Compression limits
        $maxPixels = match ($mimeType) {
            'image/jpeg', 'image/jpg' => $this->jpegMaxPixels,
            'image/png' => $this->pngMaxPixels,
            default => \PHP_INT_MAX,
        };

        if ($pixels <= $maxPixels) {
            return $filePath; // No compression needed
        }

        // Compress to limit (preserving aspect ratio)
        $scale = \sqrt($maxPixels / $pixels);
        // \max(1, ...) ensures dimensions are at least 1 pixel (required by imagecreatetruecolor)
        $newWidth = \max(1, (int) ($width * $scale));
        $newHeight = \max(1, (int) ($height * $scale));

        return $this->resizeImage($filePath, $newWidth, $newHeight, $mimeType);
    }

    private function resizeImage(string $filePath, int $newWidth, int $newHeight, string $mimeType): string
    {
        $source = match ($mimeType) {
            'image/jpeg', 'image/jpg' => \imagecreatefromjpeg($filePath),
            'image/png' => \imagecreatefrompng($filePath),
            'image/gif' => \imagecreatefromgif($filePath),
            'image/webp' => \imagecreatefromwebp($filePath),
            default => throw new \RuntimeException(\sprintf('Unsupported image type: %s', $mimeType)),
        };

        if (false === $source) {
            throw new \RuntimeException(\sprintf('Failed to create image from: %s', $filePath));
        }

        $destination = \imagecreatetruecolor($newWidth, $newHeight);
        if (false === $destination) {
            throw new \RuntimeException('Failed to create destination image');
        }

        // Preserve transparency for PNG
        if ('image/png' === $mimeType) {
            \imagealphablending($destination, false);
            \imagesavealpha($destination, true);
            $transparent = \imagecolorallocatealpha($destination, 0, 0, 0, 127);
            if (false !== $transparent) {
                \imagefill($destination, 0, 0, $transparent);
            }
        }

        \imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, \imagesx($source), \imagesy($source));

        $tempFile = \tempnam(\sys_get_temp_dir(), 'gphotos_');
        if (false === $tempFile) {
            throw new \RuntimeException('Failed to create temp file');
        }

        $success = match ($mimeType) {
            'image/jpeg', 'image/jpg' => \imagejpeg($destination, $tempFile, 95),
            'image/png' => \imagepng($destination, $tempFile, 9),
            'image/gif' => \imagegif($destination, $tempFile),
            'image/webp' => \imagewebp($destination, $tempFile, 95),
            default => false,
        };

        \imagedestroy($source);
        \imagedestroy($destination);

        if (!$success) {
            \unlink($tempFile);

            throw new \RuntimeException('Failed to save resized image');
        }

        // For JPEG images, preserve EXIF data from original file
        // PHP's imagejpeg() strips all EXIF data, so we need to copy it back using exiftool
        if (\in_array($mimeType, ['image/jpeg', 'image/jpg'], true)) {
            $this->preserveExifData($filePath, $tempFile);
        }

        return $tempFile;
    }

    /**
     * Preserve EXIF data from original file to compressed file using exiftool.
     */
    private function preserveExifData(string $originalFile, string $compressedFile): void
    {
        $exiftoolPath = $this->findExifTool();
        if (null === $exiftoolPath) {
            $this->logger->warning('exiftool not available, EXIF data will be lost during compression', [
                'original_file' => $originalFile,
                'compressed_file' => $compressedFile,
            ]);

            return;
        }

        // Copy all EXIF data from original to compressed file
        // -overwrite_original modifies file in place
        // -TagsFromFile copies tags from source file
        $command = \sprintf(
            '%s -overwrite_original -TagsFromFile %s -all:all %s 2>&1',
            \escapeshellarg($exiftoolPath),
            \escapeshellarg($originalFile),
            \escapeshellarg($compressedFile)
        );

        $output = [];
        $returnCode = 0;
        @\exec($command, $output, $returnCode);

        if (0 !== $returnCode) {
            $this->logger->warning('Failed to preserve EXIF data during compression', [
                'original_file' => $originalFile,
                'compressed_file' => $compressedFile,
                'error' => \implode("\n", $output),
            ]);
        } else {
            $this->logger->debug('EXIF data preserved during compression', [
                'original_file' => $originalFile,
                'compressed_file' => $compressedFile,
            ]);
        }
    }

    /**
     * Find exiftool executable path.
     */
    private function findExifTool(): ?string
    {
        // Common paths
        $paths = [
            '/usr/bin/exiftool',
            '/usr/local/bin/exiftool',
            'exiftool', // In PATH
        ];

        foreach ($paths as $path) {
            if ('exiftool' === $path) {
                // Check if it's in PATH
                $output = [];
                $returnCode = 0;
                @\exec('which exiftool 2>&1', $output, $returnCode);
                if (0 === $returnCode && [] !== $output) {
                    return \trim($output[0]);
                }
            } elseif (\file_exists($path) && \is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
