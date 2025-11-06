<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Filesystem;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Filesystem\MetadataPreservingCopier;
use SortingPhotosByDate\Ports\FilesystemPort;

final class LocalFilesystemAdapter implements FilesystemPort
{
    public function __construct(
        private readonly MetadataPreservingCopier $copier,
    ) {
    }

    #[\Override]
    public function copyWithMetadata(FilePath $source, FilePath $destination): bool
    {
        try {
            return $this->copier->copy($source, $destination);
        } catch (\Exception $e) {
            return false;
        }
    }

    #[\Override]
    public function ensureDirectory(FilePath $directory): bool
    {
        $path = $directory->getPath();
        if (is_dir($path)) {
            return true;
        }

        return mkdir($path, 0755, true);
    }

    #[\Override]
    public function delete(FilePath $filePath): bool
    {
        if (!file_exists($filePath->getPath())) {
            return false;
        }

        return unlink($filePath->getPath());
    }

    #[\Override]
    public function exists(FilePath $filePath): bool
    {
        return file_exists($filePath->getPath());
    }

    #[\Override]
    public function getSize(FilePath $filePath): int
    {
        $size = filesize($filePath->getPath());
        if (false === $size) {
            throw new \RuntimeException("Failed to get file size: {$filePath->getPath()}");
        }

        return $size;
    }

    #[\Override]
    public function getPermissions(FilePath $filePath): int
    {
        $perms = fileperms($filePath->getPath());
        if (false === $perms) {
            throw new \RuntimeException("Failed to get file permissions: {$filePath->getPath()}");
        }

        return $perms & 0777;
    }

    #[\Override]
    public function setPermissions(FilePath $filePath, int $permissions): bool
    {
        return chmod($filePath->getPath(), $permissions);
    }

    #[\Override]
    public function getModificationTime(FilePath $filePath): int
    {
        $mtime = filemtime($filePath->getPath());
        if (false === $mtime) {
            throw new \RuntimeException("Failed to get file modification time: {$filePath->getPath()}");
        }

        return $mtime;
    }

    #[\Override]
    public function setModificationTime(FilePath $filePath, int $timestamp): bool
    {
        return touch($filePath->getPath(), $timestamp);
    }

}
