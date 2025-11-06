<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Filesystem;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\FilesystemPort;

final class LocalFilesystemAdapter implements FilesystemPort
{
    #[\Override]
    public function copyWithMetadata(FilePath $source, FilePath $destination): bool
    {
        if (!file_exists($source->getPath())) {
            throw new \InvalidArgumentException("Source file does not exist: {$source->getPath()}");
        }

        // Ensure destination directory exists
        $destinationDir = new FilePath($destination->getDirectory());
        $this->ensureDirectory($destinationDir);

        // Get source metadata before copying
        $permissions = $this->getPermissions($source);
        $mtime = $this->getModificationTime($source);
        $atime = fileatime($source->getPath());
        if (false === $atime) {
            $atime = $mtime;
        }

        // Copy file
        if (!copy($source->getPath(), $destination->getPath())) {
            return false;
        }

        // Restore metadata
        $this->setPermissions($destination, $permissions);
        $this->setModificationTime($destination, $mtime);
        touch($destination->getPath(), $mtime, $atime);

        // Copy extended attributes if supported
        $this->copyExtendedAttributes($source->getPath(), $destination->getPath());

        return true;
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

    /**
     * Copy extended attributes if supported by the filesystem.
     */
    private function copyExtendedAttributes(string $source, string $destination): void
    {
        if (!function_exists('xattr_list')) {
            return;
        }

        try {
            $attributes = xattr_list($source);
            if (false === $attributes) {
                return;
            }

            /** @var array<string> $attributes */
            foreach ($attributes as $attr) {
                $value = xattr_get($source, $attr);
                if (is_string($value)) {
                    xattr_set($destination, $attr, $value);
                }
            }
        } catch (\Exception $e) {
            // Extended attributes not supported or error occurred - ignore
        }
    }
}
