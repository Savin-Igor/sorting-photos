<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Metadata;

use Carbon\Carbon;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\MimeTypeDetectorInterface;
use SortingPhotosByDate\Ports\MetadataExtractorPort;

final class ExifAdapter implements MetadataExtractorPort
{
    public function __construct(
        private readonly MimeTypeDetectorInterface $mimeTypeDetector,
        private readonly FilesystemPort $filesystem,
    ) {
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    #[\Override]
    public function extract(string $filePath): MediaMeta
    {
        if (!function_exists('exif_read_data')) {
            throw new \RuntimeException('EXIF extension is not available');
        }

        $filePathObj = new \SortingPhotosByDate\Domain\ValueObjects\FilePath($filePath);
        if (!$this->filesystem->exists($filePathObj)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        $exifData = @exif_read_data($filePath);
        $fileInfo = new \SplFileInfo($filePath);
        $mimeType = $this->mimeTypeDetector->detectMimeType($filePath);
        $fileSize = $this->filesystem->getSize($filePathObj);

        $width = null;
        $height = null;
        if (false !== $exifData && isset($exifData['COMPUTED']['Width'], $exifData['COMPUTED']['Height'])) {
            $width = (int) $exifData['COMPUTED']['Width'];
            $height = (int) $exifData['COMPUTED']['Height'];
        }

        $additionalMetadata = [];
        if (false !== $exifData) {
            $additionalMetadata = $this->sanitizeExifData($exifData);
        }

        return new MediaMeta(
            $fileInfo->getFilename(),
            $mimeType,
            $fileSize,
            $width,
            $height,
            null,
            null,
            null,
            null,
            $additionalMetadata
        );
    }

    #[\Override]
    public function extractDate(string $filePath): MediaDate
    {
        $filePathObj = new \SortingPhotosByDate\Domain\ValueObjects\FilePath($filePath);
        if (!$this->filesystem->exists($filePathObj)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        // Priority: EXIF DateTimeOriginal > EXIF DateTime > mtime
        $exifData = @exif_read_data($filePath);
        if (false !== $exifData) {
            if (isset($exifData['DateTimeOriginal']) && is_string($exifData['DateTimeOriginal'])) {
                $date = $this->parseExifDate($exifData['DateTimeOriginal']);
                if (null !== $date) {
                    return new MediaDate($date);
                }
            }

            if (isset($exifData['DateTime']) && is_string($exifData['DateTime'])) {
                $date = $this->parseExifDate($exifData['DateTime']);
                if (null !== $date) {
                    return new MediaDate($date);
                }
            }
        }

        // Fallback to file modification time using FilesystemPort
        $mtime = $this->filesystem->getModificationTime($filePathObj);

        return MediaDate::fromTimestamp($mtime);
    }

    private function parseExifDate(string $dateString): ?Carbon
    {
        try {
            // EXIF date format: "YYYY:MM:DD HH:MM:SS"
            $dateString = str_replace(':', '-', substr($dateString, 0, 10)).' '.substr($dateString, 11);

            return Carbon::parse($dateString);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function sanitizeExifData(array $exifData): array
    {
        $sanitized = [];
        foreach ($exifData as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $sanitized[$key] = $value;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeExifData($value);
            }
        }

        return $sanitized;
    }
}
