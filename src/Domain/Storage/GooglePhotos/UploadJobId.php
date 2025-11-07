<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Storage\GooglePhotos;

final readonly class UploadJobId implements \Stringable
{
    public function __construct(
        private string $id,
    ) {
        if ('' === $this->id) {
            throw new \InvalidArgumentException('UploadJobId cannot be empty');
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }

    public static function generate(): self
    {
        return new self(\bin2hex(\random_bytes(16)));
    }

    public static function fromString(string $id): self
    {
        return new self($id);
    }
}
