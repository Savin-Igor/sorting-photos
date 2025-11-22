<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Exceptions;

/**
 * Exception for metadata extraction errors.
 */
final class MetadataException extends \RuntimeException
{
    private const string FAILED_EXTRACT = 'Failed to extract metadata from file: %s';
    private const string FAILED_EXTRACT_DATE = 'Failed to extract date from file: %s';
    private const string FAILED_GET_TIMESTAMP = 'Failed to get file timestamp: %s';
    private const string NO_EXTRACTOR_FOUND = 'No extractor found for MIME type: %s';
    private const string EXIF_NOT_AVAILABLE = 'EXIF extension is not available';

    public static function failedExtract(string $filePath, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_EXTRACT, $filePath), 0, $previous);
    }

    public static function failedExtractDate(string $filePath, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_EXTRACT_DATE, $filePath), 0, $previous);
    }

    public static function failedGetTimestamp(string $filePath, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_GET_TIMESTAMP, $filePath), 0, $previous);
    }

    public static function noExtractorFound(string $mimeType): self
    {
        return new self(\sprintf(self::NO_EXTRACTOR_FOUND, $mimeType));
    }

    public static function exifNotAvailable(): self
    {
        return new self(self::EXIF_NOT_AVAILABLE);
    }
}
