<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\ValueObjects;

final readonly class FilePath implements \Stringable
{
    public function __construct(
        private string $path,
    ) {
        if ('' === $this->path || '0' === $this->path) {
            throw new \InvalidArgumentException('File path cannot be empty');
        }
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDirectory(): string
    {
        return \dirname($this->path);
    }

    public function getBasename(): string
    {
        return \basename($this->path);
    }

    public function getExtension(): string
    {
        $extension = \pathinfo($this->path, \PATHINFO_EXTENSION);

        return $extension ?: '';
    }

    public function getFilenameWithoutExtension(): string
    {
        $filename = \pathinfo($this->path, \PATHINFO_FILENAME);

        return $filename ?: '';
    }

    public function __toString(): string
    {
        return $this->path;
    }
}
