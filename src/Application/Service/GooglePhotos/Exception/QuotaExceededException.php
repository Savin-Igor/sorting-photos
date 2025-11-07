<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos\Exception;

final class QuotaExceededException extends \RuntimeException
{
    private function __construct(
        string $message,
        private readonly \DateTimeImmutable $resetTime,
    ) {
        parent::__construct($message);
    }

    public static function requestsExceeded(\DateTimeImmutable $resetTime): self
    {
        return new self(
            \sprintf('Request quota exceeded. Reset time: %s', $resetTime->format('Y-m-d H:i:s')),
            $resetTime
        );
    }

    public static function bytesExceeded(\DateTimeImmutable $resetTime): self
    {
        return new self(
            \sprintf('Bytes quota exceeded. Reset time: %s', $resetTime->format('Y-m-d H:i:s')),
            $resetTime
        );
    }

    public function getResetTime(): \DateTimeImmutable
    {
        return $this->resetTime;
    }
}
