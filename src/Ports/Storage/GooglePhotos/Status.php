<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

final readonly class Status
{
    public function __construct(
        private int $code,
        private string $message,
    ) {
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function isSuccess(): bool
    {
        return 0 === $this->code;
    }
}
