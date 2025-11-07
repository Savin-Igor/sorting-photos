<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

final readonly class UploadStatus
{
    public function __construct(
        private bool $isComplete,
        private ?string $uploadToken = null,
        private int $uploadedBytes = 0,
    ) {
    }

    public static function complete(string $uploadToken): self
    {
        return new self(isComplete: true, uploadToken: $uploadToken);
    }

    public static function incomplete(int $uploadedBytes): self
    {
        return new self(isComplete: false, uploadedBytes: $uploadedBytes);
    }

    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    public function getUploadToken(): ?string
    {
        return $this->uploadToken;
    }

    public function getUploadedBytes(): int
    {
        return $this->uploadedBytes;
    }
}
