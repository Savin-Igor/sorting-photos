<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Filesystem;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\FilesystemPort;

final class LocalFilesystemAdapter implements FilesystemPort
{
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
        $atime = fileatime($source->getPath()) ?: $mtime;

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

    public function ensureDirectory(FilePath $directory): bool
    {
        $path = $directory->getPath();
        if (is_dir($path)) {
            return true;
        }

        return mkdir($path, 0755, true);
    }

    public function delete(FilePath $filePath): bool
    {
        if (!file_exists($filePath->getPath())) {
            return false;
        }

        return unlink($filePath->getPath());
    }

    public function exists(FilePath $filePath): bool
    {
        return file_exists($filePath->getPath());
    }

    public function getSize(FilePath $filePath): int
    {
        $size = filesize($filePath->getPath());
        if ($size === false) {
            throw new \RuntimeException("Failed to get file size: {$filePath->getPath()}");
        }

        return $size;
    }

    public function getPermissions(FilePath $filePath): int
    {
        $perms = fileperms($filePath->getPath());
        if ($perms === false) {
            throw new \RuntimeException("Failed to get file permissions: {$filePath->getPath()}");
        }

        return $perms & 0777;
    }

    public function setPermissions(FilePath $filePath, int $permissions): bool
    {
        return chmod($filePath->getPath(), $permissions);
    }

    public function getModificationTime(FilePath $filePath): int
    {
        $mtime = filemtime($filePath->getPath());
        if ($mtime === false) {
            throw new \RuntimeException("Failed to get file modification time: {$filePath->getPath()}");
        }

        return $mtime;
    }

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
            if ($attributes === false) {
                return;
            }

            foreach ($attributes as $attr) {
                $value = xattr_get($source, $attr);
                if ($value !== false) {
                    xattr_set($destination, $attr, $value);
                }
            }
        } catch (\Exception $e) {
            // Extended attributes not supported or error occurred - ignore
        }
    }
}

