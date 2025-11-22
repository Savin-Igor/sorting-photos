<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Exceptions;

/**
 * Exception for file compression errors.
 */
final class CompressionException extends \RuntimeException
{
    private const string UNSUPPORTED_IMAGE_TYPE = 'Unsupported image type: %s';
    private const string FAILED_CREATE_IMAGE = 'Failed to create image from: %s';
    private const string FAILED_CREATE_DESTINATION_IMAGE = 'Failed to create destination image';
    private const string FAILED_CREATE_TEMP_FILE = 'Failed to create temp file';
    private const string FAILED_SAVE_RESIZED_IMAGE = 'Failed to save resized image';
    private const string FAILED_GET_FILE_SIZE = 'Failed to get file size: %s';
    private const string FFMPEG_COMPRESSION_FAILED = 'ffmpeg compression failed: %s';

    public static function unsupportedImageType(string $mimeType): self
    {
        return new self(\sprintf(self::UNSUPPORTED_IMAGE_TYPE, $mimeType));
    }

    public static function failedCreateImage(string $filePath): self
    {
        return new self(\sprintf(self::FAILED_CREATE_IMAGE, $filePath));
    }

    public static function failedCreateDestinationImage(): self
    {
        return new self(self::FAILED_CREATE_DESTINATION_IMAGE);
    }

    public static function failedCreateTempFile(): self
    {
        return new self(self::FAILED_CREATE_TEMP_FILE);
    }

    public static function failedSaveResizedImage(): self
    {
        return new self(self::FAILED_SAVE_RESIZED_IMAGE);
    }

    public static function failedGetFileSize(string $filePath): self
    {
        return new self(\sprintf(self::FAILED_GET_FILE_SIZE, $filePath));
    }

    public static function ffmpegCompressionFailed(string $output): self
    {
        return new self(\sprintf(self::FFMPEG_COMPRESSION_FAILED, $output));
    }
}
