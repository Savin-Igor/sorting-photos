<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Storage\GooglePhotos;

use SortingPhotosByDate\Exceptions\ValidationException;

final readonly class ResumableSession
{
    private const int SESSION_TTL_DAYS = 7;

    public function __construct(
        private string $sessionUri,
        private int $uploadedBytes,
        private int $totalBytes,
        private \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $expiresAt,
    ) {
        if ($this->uploadedBytes < 0) {
            throw ValidationException::negativeValue('Uploaded bytes');
        }
        if ($this->totalBytes <= 0) {
            throw ValidationException::positiveValue('Total bytes');
        }
        if ($this->uploadedBytes > $this->totalBytes) {
            throw ValidationException::invalidRange('Uploaded bytes', 'total bytes');
        }
        if ('' === $this->sessionUri) {
            throw ValidationException::emptyValue('Session URI');
        }
    }

    public static function create(
        string $sessionUri,
        int $totalBytes,
        \DateTimeImmutable $createdAt,
    ): self {
        $expiresAt = $createdAt->modify(\sprintf('+%d days', self::SESSION_TTL_DAYS));

        return new self(
            sessionUri: $sessionUri,
            uploadedBytes: 0,
            totalBytes: $totalBytes,
            createdAt: $createdAt,
            expiresAt: $expiresAt,
        );
    }

    public function withProgress(int $newUploadedBytes): self
    {
        if ($newUploadedBytes < $this->uploadedBytes) {
            throw ValidationException::bytesCannotDecrease();
        }
        if ($newUploadedBytes > $this->totalBytes) {
            throw ValidationException::invalidRange('Uploaded bytes', 'total bytes');
        }

        return new self(
            sessionUri: $this->sessionUri,
            uploadedBytes: $newUploadedBytes,
            totalBytes: $this->totalBytes,
            createdAt: $this->createdAt,
            expiresAt: $this->expiresAt,
        );
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isComplete(): bool
    {
        return $this->uploadedBytes >= $this->totalBytes;
    }

    public function getProgressPercent(): float
    {
        if ($this->totalBytes <= 0) {
            return 0.0;
        }

        return ($this->uploadedBytes / $this->totalBytes) * 100.0;
    }

    public function getSessionUri(): string
    {
        return $this->sessionUri;
    }

    public function getUploadedBytes(): int
    {
        return $this->uploadedBytes;
    }

    public function getTotalBytes(): int
    {
        return $this->totalBytes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
