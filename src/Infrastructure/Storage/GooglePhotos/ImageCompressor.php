<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

final class ImageCompressor
{
    private const int JPEG_MAX_PIXELS = 75_000_000; // 75 МП
    private const int PNG_MAX_PIXELS = 200_000_000; // 200 МП

    public function compressIfNeeded(string $filePath, string $mimeType): string
    {
        $imageInfo = \getimagesize($filePath);
        if (false === $imageInfo) {
            return $filePath; // Не удалось определить размер, оставляем как есть
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $pixels = $width * $height;

        // Лимиты Google Photos
        $maxPixels = match ($mimeType) {
            'image/jpeg', 'image/jpg' => self::JPEG_MAX_PIXELS,
            'image/png' => self::PNG_MAX_PIXELS,
            default => \PHP_INT_MAX,
        };

        if ($pixels <= $maxPixels) {
            return $filePath; // Не нужно сжимать
        }

        // Сжать до лимита (сохраняя пропорции)
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
