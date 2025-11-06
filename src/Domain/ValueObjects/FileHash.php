<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\ValueObjects;

final class FileHash
{
    private const MAX_HASH_LENGTH = 64;
    private const DEFAULT_SHORT_HASH_LENGTH = 8;

    public function __construct(
        private readonly string $hash
    ) {
        if (empty($this->hash)) {
            throw new \InvalidArgumentException('File hash cannot be empty');
        }

        if (!\ctype_xdigit($this->hash)) {
            throw new \InvalidArgumentException('File hash must be hexadecimal');
        }
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getShortHash(int $length = self::DEFAULT_SHORT_HASH_LENGTH): string
    {
        if ($length < 1 || $length > self::MAX_HASH_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Hash length must be between 1 and %d', self::MAX_HASH_LENGTH));
        }

        return \substr($this->hash, 0, $length);
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }

    public function __toString(): string
    {
        return $this->hash;
    }

    public static function fromFile(string $filePath): self
    {
        if (!\file_exists($filePath)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        $hash = \hash_file('sha256', $filePath);
        if (false === $hash) {
            throw new \RuntimeException("Failed to calculate hash for file: {$filePath}");
        }

        return new self($hash);
    }
}

