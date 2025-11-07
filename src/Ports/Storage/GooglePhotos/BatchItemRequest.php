<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

final readonly class BatchItemRequest
{
    public function __construct(
        private string $uploadToken,
        private \DateTimeImmutable $creationTime,
        private string $filename,
        private string $mimeType,
    ) {
        if ('' === $this->uploadToken) {
            throw new \InvalidArgumentException('Upload token cannot be empty');
        }
        if ('' === $this->filename) {
            throw new \InvalidArgumentException('Filename cannot be empty');
        }
    }

    public function getUploadToken(): string
    {
        return $this->uploadToken;
    }

    public function getCreationTime(): \DateTimeImmutable
    {
        return $this->creationTime;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }
}
