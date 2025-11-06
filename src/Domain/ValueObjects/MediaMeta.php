<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\ValueObjects;

final class MediaMeta
{
    public function __construct(
        private readonly string $fileName,
        private readonly string $mimeType,
        private readonly int $fileSize,
        private readonly ?int $width = null,
        private readonly ?int $height = null,
        private readonly ?int $duration = null,
        private readonly ?string $artist = null,
        private readonly ?string $title = null,
        private readonly ?string $album = null,
        private array $additionalMetadata = [],
    ) {
        if ('' === $this->fileName || '0' === $this->fileName) {
            throw new \InvalidArgumentException('File name cannot be empty');
        }

        if ($this->fileSize < 0) {
            throw new \InvalidArgumentException('File size cannot be negative');
        }
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function getArtist(): ?string
    {
        return $this->artist;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getAlbum(): ?string
    {
        return $this->album;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getAdditionalMetadata(): array
    {
        return $this->additionalMetadata;
    }

    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->additionalMetadata[$key] ?? $default;
    }

    public function hasMetadata(string $key): bool
    {
        return \array_key_exists($key, $this->additionalMetadata);
    }
}
