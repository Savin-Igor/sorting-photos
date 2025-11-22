<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos\Exception;

final class ServiceUnavailableException extends \RuntimeException
{
    private const string SERVICE_TEMPORARILY_UNAVAILABLE = 'Service temporarily unavailable';

    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function serviceTemporarilyUnavailable(): self
    {
        return new self(self::SERVICE_TEMPORARILY_UNAVAILABLE);
    }
}
