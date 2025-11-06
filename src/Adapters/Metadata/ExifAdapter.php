<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Metadata;

use Carbon\Carbon;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\MimeTypeDetectorInterface;
use SortingPhotosByDate\Ports\MetadataExtractorPort;

final readonly class ExifAdapter implements MetadataExtractorPort
{
    public function __construct(
        private MimeTypeDetectorInterface $mimeTypeDetector,
        private FilesystemPort $filesystem,
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
        if (false !== $exifData && is_array($exifData) && isset($exifData['COMPUTED']) && isset($exifData['COMPUTED']['Width'], $exifData['COMPUTED']['Height'])) {
            /** @var array<string, mixed> $exifDataArray */
            $exifDataArray = $exifData;
            $computed = $exifDataArray['COMPUTED'];
            if (is_array($computed)) {
                /** @var array<string, mixed> $computedArray */
                $computedArray = $computed;
                $computedWidth = $computedArray['Width'] ?? null;
                $computedHeight = $computedArray['Height'] ?? null;
                if (is_numeric($computedWidth) && is_numeric($computedHeight)) {
                    $width = (int) $computedWidth;
                    $height = (int) $computedHeight;
                }
            }
        }

        /** @var array<string, mixed> $additionalMetadata */
        $additionalMetadata = [];
        if (false !== $exifData && is_array($exifData)) {
            /** @var array<string, mixed> $exifDataArray */
            $exifDataArray = $exifData;
            $additionalMetadata = $this->sanitizeExifData($exifDataArray);
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
                if ($date instanceof Carbon) {
                    return new MediaDate($date);
                }
            }

            if (isset($exifData['DateTime']) && is_string($exifData['DateTime'])) {
                $date = $this->parseExifDate($exifData['DateTime']);
                if ($date instanceof Carbon) {
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
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $exifData
     *
     * @return array<string, mixed>
     */
    private function sanitizeExifData(array $exifData): array
    {
        /** @var array<string, mixed> $sanitized */
        $sanitized = [];
        foreach ($exifData as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $sanitized[$key] = $value;
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $valueArray */
                $valueArray = $value;
                $sanitized[$key] = $this->sanitizeExifData($valueArray);
            }
        }

        return $sanitized;
    }
}
