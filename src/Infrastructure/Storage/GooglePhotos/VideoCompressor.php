<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

final class VideoCompressor
{
    public function __construct(
        private readonly bool $enabled,
        private readonly int $maxSizeBytes,
    ) {
    }

    public function compressIfNeeded(string $filePath): string
    {
        // If compression is disabled, return original file
        if (!$this->enabled) {
            return $filePath;
        }

        $fileSize = \filesize($filePath);
        if (false === $fileSize) {
            throw new \RuntimeException(\sprintf('Failed to get file size: %s', $filePath));
        }

        if ($fileSize <= $this->maxSizeBytes) {
            return $filePath; // No compression needed
        }

        // Use ffmpeg for compression (if available)
        if (!$this->isFfmpegAvailable()) {
            // If ffmpeg is not available, return original
            // In production, you might want to throw an exception or use another method
            return $filePath;
        }

        return $this->compressVideo($filePath);
    }

    private function isFfmpegAvailable(): bool
    {
        $output = [];
        $returnVar = 0;
        \exec('which ffmpeg 2>&1', $output, $returnVar);

        return 0 === $returnVar;
    }

    private function compressVideo(string $filePath): string
    {
        $tempFile = \tempnam(\sys_get_temp_dir(), 'gphotos_video_');
        if (false === $tempFile) {
            throw new \RuntimeException('Failed to create temp file');
        }
        \unlink($tempFile);
        $tempFile .= '.mp4';

        // Использовать ffmpeg для сжатия без потери качества (или с минимальной потерей)
        $command = \sprintf(
            'ffmpeg -i %s -c:v libx264 -crf 18 -preset slow -c:a copy %s 2>&1',
            \escapeshellarg($filePath),
            \escapeshellarg($tempFile)
        );

        $output = [];
        $returnVar = 0;
        \exec($command, $output, $returnVar);

        if (0 !== $returnVar) {
            \unlink($tempFile);

            throw new \RuntimeException(\sprintf('ffmpeg compression failed: %s', \implode("\n", $output)));
        }

        return $tempFile;
    }
}
