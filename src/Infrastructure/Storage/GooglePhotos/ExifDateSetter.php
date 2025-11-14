<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use SortingPhotosByDate\Ports\LoggerPort;

/**
 * Service to set EXIF DateTimeOriginal in image files.
 * Uses exiftool if available, otherwise falls back to PHP EXIF manipulation.
 */
final readonly class ExifDateSetter
{
    public function __construct(
        private LoggerPort $logger,
    ) {
    }

    /**
     * Set creation time in EXIF metadata of image or video file.
     * If DateTimeOriginal is already present, does nothing.
     *
     * @param string             $filePath     Path to image or video file
     * @param \DateTimeImmutable $creationTime Creation time to set
     *
     * @return bool True if date was set or already present, false on failure
     */
    public function setCreationTime(string $filePath, \DateTimeImmutable $creationTime): bool
    {
        // Check if it's a JPEG image
        if ($this->isJpegImage($filePath)) {
            return $this->setImageCreationTime($filePath, $creationTime);
        }

        // Check if it's a video file
        if ($this->isVideoFile($filePath)) {
            return $this->setVideoCreationTime($filePath, $creationTime);
        }

        // Not an image or video, skip
        return true;
    }

    /**
     * Set creation time for JPEG images.
     */
    private function setImageCreationTime(string $filePath, \DateTimeImmutable $creationTime): bool
    {

        // Check if DateTimeOriginal already exists
        $exifData = @\exif_read_data($filePath);
        if (false !== $exifData && isset($exifData['DateTimeOriginal']) && '' !== $exifData['DateTimeOriginal']) {
            $this->logger->debug('EXIF DateTimeOriginal already exists, skipping', [
                'file_path' => $filePath,
                'existing_date' => $exifData['DateTimeOriginal'],
            ]);

            return true;
        }

        // Try using exiftool (most reliable)
        if ($this->setWithExifTool($filePath, $creationTime)) {
            $this->logger->info('Set EXIF DateTimeOriginal using exiftool', [
                'file_path' => $filePath,
                'creation_time' => $creationTime->format('Y-m-d H:i:s'),
            ]);

            return true;
        }

        // Fallback: Try using PHP (limited support)
        if ($this->setWithPhp()) {
            $this->logger->info('Set EXIF DateTimeOriginal using PHP', [
                'file_path' => $filePath,
                'creation_time' => $creationTime->format('Y-m-d H:i:s'),
            ]);

            return true;
        }

        $this->logger->warning('Failed to set EXIF DateTimeOriginal', [
            'file_path' => $filePath,
            'creation_time' => $creationTime->format('Y-m-d H:i:s'),
        ]);

        return false;
    }

    private function isJpegImage(string $filePath): bool
    {
        $mimeType = \mime_content_type($filePath);
        if (false === $mimeType) {
            return false;
        }

        return \in_array($mimeType, ['image/jpeg', 'image/jpg'], true);
    }

    /**
     * Check if file is a video file.
     */
    private function isVideoFile(string $filePath): bool
    {
        $mimeType = \mime_content_type($filePath);
        if (false === $mimeType) {
            // Fallback to extension check
            $extension = \strtolower(\pathinfo($filePath, \PATHINFO_EXTENSION));
            $videoExtensions = ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'flv', 'webm', 'm4v', '3gp'];

            return \in_array($extension, $videoExtensions, true);
        }

        return \str_starts_with($mimeType, 'video/');
    }

    /**
     * Set creation time for video files using exiftool or ffmpeg.
     */
    private function setVideoCreationTime(string $filePath, \DateTimeImmutable $creationTime): bool
    {
        // Try using exiftool first (works for most video formats)
        if ($this->setVideoWithExifTool($filePath, $creationTime)) {
            $this->logger->info('Set video creation time using exiftool', [
                'file_path' => $filePath,
                'creation_time' => $creationTime->format('Y-m-d H:i:s'),
            ]);

            return true;
        }

        // Fallback: Try using ffmpeg (more reliable for MP4)
        if ($this->setVideoWithFfmpeg($filePath, $creationTime)) {
            $this->logger->info('Set video creation time using ffmpeg', [
                'file_path' => $filePath,
                'creation_time' => $creationTime->format('Y-m-d H:i:s'),
            ]);

            return true;
        }

        $this->logger->warning('Failed to set video creation time', [
            'file_path' => $filePath,
            'creation_time' => $creationTime->format('Y-m-d H:i:s'),
        ]);

        return false;
    }

    /**
     * Set video creation time using exiftool.
     */
    private function setVideoWithExifTool(string $filePath, \DateTimeImmutable $creationTime): bool
    {
        $exiftoolPath = $this->findExifTool();
        if (null === $exiftoolPath) {
            return false;
        }

        // Format: YYYY:MM:DD HH:MM:SS (EXIF format)
        $exifDate = $creationTime->format('Y:m:d H:i:s');

        // For videos, use CreateDate and DateTimeOriginal tags
        $command = \sprintf(
            '%s -overwrite_original -CreateDate="%s" -DateTimeOriginal="%s" -MediaCreateDate="%s" %s',
            \escapeshellarg($exiftoolPath),
            \escapeshellarg($exifDate),
            \escapeshellarg($exifDate),
            \escapeshellarg($exifDate),
            \escapeshellarg($filePath)
        );

        $output = [];
        $returnCode = 0;
        @\exec($command.' 2>&1', $output, $returnCode);

        return 0 === $returnCode;
    }

    /**
     * Set video creation time using ffmpeg (for MP4 files).
     */
    private function setVideoWithFfmpeg(string $filePath, \DateTimeImmutable $creationTime): bool
    {
        // Check if ffmpeg is available
        $output = [];
        $returnVar = 0;
        \exec('which ffmpeg 2>&1', $output, $returnVar);
        if (0 !== $returnVar) {
            return false;
        }

        // Format: YYYY-MM-DDTHH:MM:SS+00:00 (ISO 8601, ffmpeg format)
        // Use UTC timezone for consistency
        $utcTime = $creationTime->setTimezone(new \DateTimeZone('UTC'));
        $creationTimeStr = $utcTime->format('Y-m-d\TH:i:s\Z');

        // Create temporary file
        $tempFile = \tempnam(\sys_get_temp_dir(), 'gphotos_video_meta_');
        if (false === $tempFile) {
            return false;
        }
        \unlink($tempFile);
        $tempFile .= '.'.pathinfo($filePath, \PATHINFO_EXTENSION);

        // Use ffmpeg to copy video and set metadata
        $command = \sprintf(
            'ffmpeg -i %s -c copy -metadata creation_time="%s" %s 2>&1',
            \escapeshellarg($filePath),
            \escapeshellarg($creationTimeStr),
            \escapeshellarg($tempFile)
        );

        $output = [];
        $returnVar = 0;
        @\exec($command, $output, $returnVar);

        if (0 === $returnVar && \file_exists($tempFile)) {
            // Replace original file with temp file
            \rename($tempFile, $filePath);

            return true;
        }

        // Cleanup on failure
        if (\file_exists($tempFile)) {
            \unlink($tempFile);
        }

        return false;
    }

    /**
     * Set EXIF date using exiftool (if available).
     */
    private function setWithExifTool(string $filePath, \DateTimeImmutable $creationTime): bool
    {
        // Check if exiftool is available
        $exiftoolPath = $this->findExifTool();
        if (null === $exiftoolPath) {
            return false;
        }

        // Format: YYYY:MM:DD HH:MM:SS (EXIF format)
        $exifDate = $creationTime->format('Y:m:d H:i:s');

        $command = \sprintf(
            '%s -overwrite_original -DateTimeOriginal="%s" -DateTime="%s" %s',
            \escapeshellarg($exiftoolPath),
            \escapeshellarg($exifDate),
            \escapeshellarg($exifDate),
            \escapeshellarg($filePath)
        );

        $output = [];
        $returnCode = 0;
        @\exec($command.' 2>&1', $output, $returnCode);

        return 0 === $returnCode;
    }

    /**
     * Set EXIF date using PHP (limited - only works for some JPEG files).
     */
    private function setWithPhp(): bool
    {
        // PHP doesn't have built-in EXIF writing support
        // This is a placeholder - would need external library like lsolesen/pel
        // For now, return false to indicate it's not supported
        return false;
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
