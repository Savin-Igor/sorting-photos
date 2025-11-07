<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Storage;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Storage item value object representing a file/item in a storage.
 */
final readonly class StorageItem
{
    public function __construct(
        private string $identifier,
        private StorageType $type,
        private string $path,
        private ?int $size = null,
        private ?int $modifiedTime = null,
    ) {
        if ('' === $this->identifier) {
            throw new \InvalidArgumentException('Storage item identifier cannot be empty');
        }
        if ('' === $this->path) {
            throw new \InvalidArgumentException('Storage item path cannot be empty');
        }
    }

    /**
     * Create storage item from FilePath (for local storage compatibility).
     */
    public static function fromFilePath(FilePath $filePath, StorageType $type = StorageType::LOCAL): self
    {
        return new self(
            identifier: $filePath->getPath(),
            type: $type,
            path: $filePath->getPath(),
        );
    }

    /**
     * Convert to FilePath (for backward compatibility with existing code).
     */
    public function toFilePath(): FilePath
    {
        return new FilePath($this->path);
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getType(): StorageType
    {
        return $this->type;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getModifiedTime(): ?int
    {
        return $this->modifiedTime;
    }

    public function withSize(int $size): self
    {
        return new self(
            identifier: $this->identifier,
            type: $this->type,
            path: $this->path,
            size: $size,
            modifiedTime: $this->modifiedTime,
        );
    }

    public function withModifiedTime(int $modifiedTime): self
    {
        return new self(
            identifier: $this->identifier,
            type: $this->type,
            path: $this->path,
            size: $this->size,
            modifiedTime: $modifiedTime,
        );
    }
}
