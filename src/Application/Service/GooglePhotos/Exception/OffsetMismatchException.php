<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos\Exception;

final class OffsetMismatchException extends \RuntimeException
{
    private const string FAILED_UPLOAD_CHUNK = 'Failed to upload chunk: %s %s';

    private function __construct(
        string $message,
        private readonly int $expectedOffset,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function failedUploadChunk(int $statusCode, string $body, int $expectedOffset): self
    {
        return new self(
            \sprintf(self::FAILED_UPLOAD_CHUNK, $statusCode, $body),
            $expectedOffset
        );
    }

    public function getExpectedOffset(): int
    {
        return $this->expectedOffset;
    }
}
