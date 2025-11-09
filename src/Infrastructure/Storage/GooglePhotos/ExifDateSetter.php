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
     * Set creation time in EXIF metadata of image file.
     * If DateTimeOriginal is already present, does nothing.
     *
     * @param string             $filePath     Path to image file
     * @param \DateTimeImmutable $creationTime Creation time to set
     *
     * @return bool True if date was set or already present, false on failure
     */
    public function setCreationTime(string $filePath, \DateTimeImmutable $creationTime): bool
    {
        // Only process JPEG images
        if (!$this->isJpegImage($filePath)) {
            return true; // Not an image, skip
        }

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
