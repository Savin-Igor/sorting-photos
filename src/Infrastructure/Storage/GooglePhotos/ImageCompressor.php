<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

final class ImageCompressor
{
    public function __construct(
        private readonly bool $enabled,
        private readonly int $jpegMaxPixels,
        private readonly int $pngMaxPixels,
    ) {
    }

    public function compressIfNeeded(string $filePath, string $mimeType): string
    {
        // If compression is disabled, return original file
        if (!$this->enabled) {
            return $filePath;
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

        // Сохранить прозрачность для PNG
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

        return $tempFile;
    }
}
