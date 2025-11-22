<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\ValueObjects;

use SortingPhotosByDate\Exceptions\FileOperationException;
use SortingPhotosByDate\Exceptions\ValidationException;

final readonly class FileHash implements \Stringable
{
    private const int MAX_HASH_LENGTH = 64;
    private const int DEFAULT_SHORT_HASH_LENGTH = 8;

    public function __construct(
        private string $hash,
    ) {
        if ('' === $this->hash || '0' === $this->hash) {
            throw ValidationException::emptyValue('File hash');
        }

        if (!\ctype_xdigit($this->hash)) {
            throw ValidationException::invalidHexFormat();
        }
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getShortHash(int $length = self::DEFAULT_SHORT_HASH_LENGTH): string
    {
        if ($length < 1 || $length > self::MAX_HASH_LENGTH) {
            throw ValidationException::hashLengthOutOfRange(self::MAX_HASH_LENGTH);
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
            throw ValidationException::fileNotExists($filePath);
        }

        $hash = \hash_file('sha256', $filePath);
        if (false === $hash) {
            throw FileOperationException::failedCalculateHash($filePath);
        }

        return new self($hash);
    }
}
