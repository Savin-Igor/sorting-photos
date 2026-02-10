<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\RateLimiting;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\FilesystemPort;

/**
 * Decorator that adds rate limiting to FilesystemPort.
 */
final readonly class RateLimitedFilesystemPort implements FilesystemPort
{
    public function __construct(
        private FilesystemPort $inner,
        private RateLimiter $rateLimiter,
    ) {
    }

    public function copyWithMetadata(FilePath $source, FilePath $destination): bool
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->copyWithMetadata($source, $destination);
    }

    public function ensureDirectory(FilePath $directory): bool
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->ensureDirectory($directory);
    }

    public function delete(FilePath $filePath): bool
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->delete($filePath);
    }

    public function exists(FilePath $filePath): bool
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->exists($filePath);
    }

    public function getSize(FilePath $filePath): int
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->getSize($filePath);
    }

    public function getPermissions(FilePath $filePath): int
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->getPermissions($filePath);
    }

    public function setPermissions(FilePath $filePath, int $permissions): bool
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->setPermissions($filePath, $permissions);
    }

    public function getModificationTime(FilePath $filePath): int
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->getModificationTime($filePath);
    }

    public function setModificationTime(FilePath $filePath, int $timestamp): bool
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->setModificationTime($filePath, $timestamp);
    }

    public function getAccessTime(FilePath $filePath): int
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->getAccessTime($filePath);
    }

    public function setTimestamps(FilePath $filePath, int $mtime, int $atime): bool
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->setTimestamps($filePath, $mtime, $atime);
    }

    public function read(FilePath $filePath): string
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->read($filePath);
    }

    public function calculateHash(FilePath $filePath): string
    {
        $this->rateLimiter->waitIfNeeded();

        return $this->inner->calculateHash($filePath);
    }

    public function write(FilePath $filePath, string $content): void
    {
        $this->rateLimiter->waitIfNeeded();
        $this->inner->write($filePath, $content);
    }
}
