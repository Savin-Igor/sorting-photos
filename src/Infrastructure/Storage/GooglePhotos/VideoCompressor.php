<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

final readonly class VideoCompressor
{
    public function __construct(
        private bool $enabled,
        private int $maxSizeBytes,
    ) {
    }

    /**
     * Compress video if needed, preserving metadata (especially creation time).
     *
     * @param string                  $filePath     Path to video file
     * @param \DateTimeImmutable|null $creationTime Creation time to preserve in metadata
     *
     * @return string Path to compressed file (or original if compression not needed)
     */
    public function compressIfNeeded(string $filePath, ?\DateTimeImmutable $creationTime = null): string
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

        return $this->compressVideo($filePath, $creationTime);
    }

    private function isFfmpegAvailable(): bool
    {
        $output = [];
        $returnVar = 0;
        \exec('which ffmpeg 2>&1', $output, $returnVar);

        return 0 === $returnVar;
    }

    private function compressVideo(string $filePath, ?\DateTimeImmutable $creationTime = null): string
    {
        $tempFile = \tempnam(\sys_get_temp_dir(), 'gphotos_video_');
        if (false === $tempFile) {
            throw new \RuntimeException('Failed to create temp file');
        }
        \unlink($tempFile);
        $tempFile .= '.mp4';

        // Build ffmpeg command with metadata preservation
        // -map_metadata 0 copies all metadata from input file (more reliable than 0:0)
        // -metadata creation_time sets the creation time explicitly
        // -movflags +faststart enables web optimization without re-encoding
        $commandParts = [
            'ffmpeg',
            '-i', \escapeshellarg($filePath),
            '-c:v', 'libx264',
            '-crf', '18', // High quality (lower = better quality, 18 is visually lossless)
            '-preset', 'slow', // Better compression efficiency
            '-c:a', 'copy', // Copy audio without re-encoding
            '-map_metadata', '0', // Copy all metadata from input file (more reliable)
            '-movflags', '+faststart', // Enable web optimization
        ];

        // Set creation_time metadata if provided
        if ($creationTime instanceof \DateTimeImmutable) {
            // Format: YYYY-MM-DDTHH:MM:SS+00:00 (ISO 8601, ffmpeg format)
            // Use UTC timezone for consistency
            $utcTime = $creationTime->setTimezone(new \DateTimeZone('UTC'));
            $creationTimeStr = $utcTime->format('Y-m-d\TH:i:s\Z');
            $commandParts[] = '-metadata';
            $commandParts[] = \sprintf('creation_time=%s', \escapeshellarg($creationTimeStr));
        }

        $commandParts[] = \escapeshellarg($tempFile);
        $commandParts[] = '2>&1';

        $command = \implode(' ', $commandParts);

        $output = [];
        $returnVar = 0;
        \exec($command, $output, $returnVar);

        if (0 !== $returnVar) {
            if (\file_exists($tempFile)) {
                \unlink($tempFile);
            }

            throw new \RuntimeException(\sprintf('ffmpeg compression failed: %s', \implode("\n", $output)));
        }

        return $tempFile;
    }
}
