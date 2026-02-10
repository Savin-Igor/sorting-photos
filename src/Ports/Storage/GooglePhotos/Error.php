<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

final readonly class Error
{
    public function __construct(
        private int $code,
        private string $message,
        private ?string $domain = null,
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

    public function getDomain(): ?string
    {
        return $this->domain;
    }
}
