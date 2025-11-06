<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Filesystem;

use League\Flysystem\FilesystemOperator;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Filesystem\MetadataPreservingCopier;
use SortingPhotosByDate\Ports\FilesystemPort;

/**
 * Local filesystem adapter using Flysystem.
 * All filesystem operations go through Flysystem instead of native PHP functions.
 * Implements FilesystemPort interface.
 */
final readonly class LocalFilesystemAdapter implements FilesystemPort
{
    public function __construct(private MetadataPreservingCopier $copier, private FilesystemOperator $filesystem, private int $defaultDirectoryPermissions = 0755)
    {
    }

    #[\Override]
    public function copyWithMetadata(FilePath $source, FilePath $destination): bool
    {
        try {
            return $this->copier->copy($source, $destination);
        } catch (\Exception) {
            return false;
        }
    }

    #[\Override]
    public function ensureDirectory(FilePath $directory): bool
    {
        $path = $directory->getPath();

        try {
            if ($this->filesystem->directoryExists($path)) {
                return true;
            }

            $this->filesystem->createDirectory($path, [
                'visibility' => $this->permissionsToVisibility($this->defaultDirectoryPermissions),
            ]);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    #[\Override]
    public function delete(FilePath $filePath): bool
    {
        $path = $filePath->getPath();

        try {
            if (!$this->filesystem->fileExists($path)) {
                return false;
            }

            $this->filesystem->delete($path);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    #[\Override]
    public function exists(FilePath $filePath): bool
    {
        $path = $filePath->getPath();

        try {
            return $this->filesystem->fileExists($path);
        } catch (\Exception) {
            return false;
        }
    }

    #[\Override]
    public function getSize(FilePath $filePath): int
    {
        $path = $filePath->getPath();

        try {
            $attributes = $this->filesystem->visibility($path);

            return $this->filesystem->fileSize($path);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to get file size: {$path}", 0, $e);
        }
    }

    #[\Override]
    public function getPermissions(FilePath $filePath): int
    {
        $path = $filePath->getPath();

        try {
            $visibility = $this->filesystem->visibility($path);

            return $this->visibilityToPermissions($visibility);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to get file permissions: {$path}", 0, $e);
        }
    }

    #[\Override]
    public function setPermissions(FilePath $filePath, int $permissions): bool
    {
        $path = $filePath->getPath();

        try {
            $this->filesystem->setVisibility($path, $this->permissionsToVisibility($permissions));

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    #[\Override]
    public function getModificationTime(FilePath $filePath): int
    {
        $path = $filePath->getPath();

        try {
            return $this->filesystem->lastModified($path);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to get file modification time: {$path}", 0, $e);
        }
    }

    #[\Override]
    public function setModificationTime(FilePath $filePath, int $timestamp): bool
    {
        // Flysystem doesn't support setting modification time directly
        // We'll need to use native PHP for this specific operation
        $path = $filePath->getPath();

        try {
            if ($this->filesystem->fileExists($path)) {
                // Use native touch for setting mtime (Flysystem limitation)
                return touch($path, $timestamp);
            }

            return false;
        } catch (\Exception) {
            return false;
        }
    }

    #[\Override]
    public function getAccessTime(FilePath $filePath): int
    {
        // Flysystem doesn't support access time directly
        // Fallback to native PHP function
        $path = $filePath->getPath();

        try {
            if (!$this->filesystem->fileExists($path)) {
                throw new \RuntimeException("File does not exist: {$path}");
            }

            $atime = fileatime($path);
            if (false === $atime) {
                throw new \RuntimeException("Failed to get file access time: {$path}");
            }

            return $atime;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to get file access time: {$path}", 0, $e);
        }
    }

    #[\Override]
    public function setTimestamps(FilePath $filePath, int $mtime, int $atime): bool
    {
        // Flysystem doesn't support setting timestamps directly
        // Use native PHP touch function
        $path = $filePath->getPath();

        try {
            if (!$this->filesystem->fileExists($path)) {
                return false;
            }

            return touch($path, $mtime, $atime);
        } catch (\Exception) {
            return false;
        }
    }

    #[\Override]
    public function read(FilePath $filePath): string
    {
        $path = $filePath->getPath();

        try {
            return $this->filesystem->read($path);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to read file: {$path}", 0, $e);
        }
    }

    #[\Override]
    public function calculateHash(FilePath $filePath): string
    {
        $path = $filePath->getPath();

        try {
            $content = $this->filesystem->read($path);

            return hash('sha256', $content);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to calculate hash for file: {$path}", 0, $e);
        }
    }

    #[\Override]
    public function write(FilePath $filePath, string $content): void
    {
        $path = $filePath->getPath();

        try {
            // Ensure directory exists
            $directory = new FilePath(dirname($path));
            $this->ensureDirectory($directory);

            $this->filesystem->write($path, $content);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to write file: {$path}", 0, $e);
        }
    }

    /**
     * Convert Flysystem visibility to Unix permissions.
     */
    private function visibilityToPermissions(string $visibility): int
    {
        // Flysystem visibility: 'public' = 0644, 'private' = 0600
        return 'public' === $visibility ? 0644 : 0600;
    }

    /**
     * Convert Unix permissions to Flysystem visibility.
     */
    private function permissionsToVisibility(int $permissions): string
    {
        // Simple mapping: if world-readable, it's public
        return (($permissions & 0044) !== 0) ? 'public' : 'private';
    }
}
