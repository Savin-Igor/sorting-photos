<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Exceptions;

/**
 * Exception for Google Photos API errors.
 */
final class GooglePhotosApiException extends \RuntimeException
{
    private const string FAILED_INITIATE_UPLOAD = 'Failed to initiate resumable upload: %s %s';
    private const string NO_UPLOAD_URL = 'No X-Goog-Upload-URL header in response';
    private const string FAILED_UPLOAD_CHUNK = 'Failed to upload chunk: %s %s';
    private const string NO_RANGE_HEADER = 'No Range header in 308 response';
    private const string RANGE_MISMATCH = 'Range mismatch: expected at least %d bytes, got %d';
    private const string NO_UPLOAD_TOKEN = 'No uploadToken in response';
    private const string EMPTY_UPLOAD_TOKEN = 'Empty upload token in response';
    private const string UPLOAD_NOT_COMPLETE = 'Upload not complete: %s %s';
    private const string FAILED_BATCH_CREATE = 'Failed to batch create media items: %s %s';
    private const string INVALID_JSON_RESPONSE = 'Invalid JSON response from API';
    private const string FAILED_LIST_MEDIA = 'Failed to list media items: %s %s';
    private const string FAILED_LIST_ALBUMS = 'Failed to list albums: %s %s';
    private const string FAILED_DECODE_JSON = 'Failed to decode JSON response: %s';
    private const string INVALID_RANGE_HEADER = 'Invalid Range header format: %s';
    private const string UNEXPECTED_STATUS_CODE = 'Unexpected status code: %s %s';

    public static function failedInitiateUpload(int $statusCode, string $body): self
    {
        return new self(\sprintf(self::FAILED_INITIATE_UPLOAD, $statusCode, $body));
    }

    public static function noUploadUrl(): self
    {
        return new self(self::NO_UPLOAD_URL);
    }

    public static function failedUploadChunk(int $statusCode, string $body): self
    {
        return new self(\sprintf(self::FAILED_UPLOAD_CHUNK, $statusCode, $body));
    }

    public static function noRangeHeader(): self
    {
        return new self(self::NO_RANGE_HEADER);
    }

    public static function rangeMismatch(int $expectedBytes, int $gotBytes): self
    {
        return new self(\sprintf(self::RANGE_MISMATCH, $expectedBytes, $gotBytes));
    }

    public static function noUploadToken(): self
    {
        return new self(self::NO_UPLOAD_TOKEN);
    }

    public static function emptyUploadToken(): self
    {
        return new self(self::EMPTY_UPLOAD_TOKEN);
    }

    public static function uploadNotComplete(int $statusCode, string $body): self
    {
        return new self(\sprintf(self::UPLOAD_NOT_COMPLETE, $statusCode, $body));
    }

    public static function failedBatchCreate(int $statusCode, string $errorBody): self
    {
        return new self(\sprintf(self::FAILED_BATCH_CREATE, $statusCode, $errorBody));
    }

    public static function invalidJsonResponse(): self
    {
        return new self(self::INVALID_JSON_RESPONSE);
    }

    public static function failedListMedia(int $statusCode, string $errorBody): self
    {
        return new self(\sprintf(self::FAILED_LIST_MEDIA, $statusCode, $errorBody));
    }

    public static function failedListAlbums(int $statusCode, string $errorBody): self
    {
        return new self(\sprintf(self::FAILED_LIST_ALBUMS, $statusCode, $errorBody));
    }

    public static function failedDecodeJson(string $error, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_DECODE_JSON, $error), 0, $previous);
    }

    public static function invalidRangeHeader(string $range): self
    {
        return new self(\sprintf(self::INVALID_RANGE_HEADER, $range));
    }

    public static function unexpectedStatusCode(int $statusCode, string $body): self
    {
        return new self(\sprintf(self::UNEXPECTED_STATUS_CODE, $statusCode, $body));
    }
}
