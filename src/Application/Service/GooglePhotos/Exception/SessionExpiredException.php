<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos\Exception;

final class SessionExpiredException extends \RuntimeException
{
    private const string RESUMABLE_SESSION_EXPIRED = 'Resumable session expired';

    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function resumableSessionExpired(): self
    {
        return new self(self::RESUMABLE_SESSION_EXPIRED);
    }
}
