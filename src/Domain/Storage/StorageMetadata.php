<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Storage;

/**
 * Storage metadata value object.
 */
final readonly class StorageMetadata
{
    /**
     * @param array<string, mixed> $customAttributes
     */
    public function __construct(
        private ?int $size = null,
        private ?int $modifiedTime = null,
        private ?int $createdTime = null,
        private ?string $mimeType = null,
        private ?string $etag = null,
        private array $customAttributes = [],
    ) {
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getModifiedTime(): ?int
    {
        return $this->modifiedTime;
    }

    public function getCreatedTime(): ?int
    {
        return $this->createdTime;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomAttributes(): array
    {
        return $this->customAttributes;
    }

    public function getCustomAttribute(string $key, mixed $default = null): mixed
    {
        return $this->customAttributes[$key] ?? $default;
    }

    public function withSize(int $size): self
    {
        return new self(
            size: $size,
            modifiedTime: $this->modifiedTime,
            createdTime: $this->createdTime,
            mimeType: $this->mimeType,
            etag: $this->etag,
            customAttributes: $this->customAttributes,
        );
    }

    public function withModifiedTime(int $modifiedTime): self
    {
        return new self(
            size: $this->size,
            modifiedTime: $modifiedTime,
            createdTime: $this->createdTime,
            mimeType: $this->mimeType,
            etag: $this->etag,
            customAttributes: $this->customAttributes,
        );
    }

    public function withMimeType(string $mimeType): self
    {
        return new self(
            size: $this->size,
            modifiedTime: $this->modifiedTime,
            createdTime: $this->createdTime,
            mimeType: $mimeType,
            etag: $this->etag,
            customAttributes: $this->customAttributes,
        );
    }

    public function withCustomAttribute(string $key, mixed $value): self
    {
        $attributes = $this->customAttributes;
        $attributes[$key] = $value;

        return new self(
            size: $this->size,
            modifiedTime: $this->modifiedTime,
            createdTime: $this->createdTime,
            mimeType: $this->mimeType,
            etag: $this->etag,
            customAttributes: $attributes,
        );
    }
}
