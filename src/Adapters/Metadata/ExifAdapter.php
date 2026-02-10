<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Metadata;

use Carbon\Carbon;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Exceptions\MetadataException;
use SortingPhotosByDate\Exceptions\ValidationException;
use SortingPhotosByDate\Infrastructure\Metadata\FilenameDateExtractor;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\MimeTypeDetectorInterface;
use SortingPhotosByDate\Ports\MetadataExtractorPort;

final readonly class ExifAdapter implements MetadataExtractorPort
{
    public function __construct(
        private MimeTypeDetectorInterface $mimeTypeDetector,
        private FilesystemPort $filesystem,
        private FilenameDateExtractor $filenameDateExtractor,
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
            throw MetadataException::exifNotAvailable();
        }

        $filePathObj = new \SortingPhotosByDate\Domain\ValueObjects\FilePath($filePath);
        if (!$this->filesystem->exists($filePathObj)) {
            throw ValidationException::fileNotExists($filePath);
        }

        $exifData = @exif_read_data($filePath);
        $fileInfo = new \SplFileInfo($filePath);
        $mimeType = $this->mimeTypeDetector->detectMimeType($filePath);
        $fileSize = $this->filesystem->getSize($filePathObj);

        $width = null;
        $height = null;
        /** @var array<string, mixed>|false $exifData */
        if (false !== $exifData && isset($exifData['COMPUTED']) && isset($exifData['COMPUTED']['Width'], $exifData['COMPUTED']['Height'])) {
            /** @var array<string, mixed> $exifDataArray */
            $exifDataArray = $exifData;
            /** @var mixed $computed */
            $computed = $exifDataArray['COMPUTED'];
            if (is_array($computed)) {
                /** @var array<string, mixed> $computedArray */
                $computedArray = $computed;
                /** @var mixed $computedWidth */
                $computedWidth = $computedArray['Width'] ?? null;
                /** @var mixed $computedHeight */
                $computedHeight = $computedArray['Height'] ?? null;
                if (is_numeric($computedWidth) && is_numeric($computedHeight)) {
                    $width = (int) $computedWidth;
                    $height = (int) $computedHeight;
                }
            }
        }

        /** @var array<string, mixed> $additionalMetadata */
        $additionalMetadata = [];
        /** @var array<string, mixed>|false $exifDataForMetadata */
        $exifDataForMetadata = $exifData;
        if (false !== $exifDataForMetadata) {
            /** @var array<string, mixed> $exifDataArray */
            $exifDataArray = $exifDataForMetadata;
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
            throw ValidationException::fileNotExists($filePath);
        }

        // Priority 1: Extract from filename (most reliable - filename changes less often than metadata)
        $filenameDate = $this->filenameDateExtractor->extract($filePath);
        if ($filenameDate instanceof Carbon) {
            return new MediaDate($filenameDate);
        }

        // Priority 2: EXIF DateTimeOriginal > EXIF DateTime
        $exifData = @exif_read_data($filePath);
        if (false !== $exifData) {
            if (isset($exifData['DateTimeOriginal']) && is_string($exifData['DateTimeOriginal'])) {
                $date = $this->parseExifDate($exifData['DateTimeOriginal']);
                if ($date instanceof Carbon && $this->isValidDate($date)) {
                    return new MediaDate($date);
                }
            }

            if (isset($exifData['DateTime']) && is_string($exifData['DateTime'])) {
                $date = $this->parseExifDate($exifData['DateTime']);
                if ($date instanceof Carbon && $this->isValidDate($date)) {
                    return new MediaDate($date);
                }
            }
        }

        // Priority 3: Fallback to file modification time
        $mtime = $this->filesystem->getModificationTime($filePathObj);

        return MediaDate::fromTimestamp($mtime);
    }

    private function isValidDate(Carbon $date): bool
    {
        $year = $date->year;

        // Accept dates between 1900 and 2100
        return $year >= 1900 && $year <= 2100;
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
        /**
         * @var string $key
         * @var mixed  $value
         */
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
