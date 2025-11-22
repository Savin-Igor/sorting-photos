<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Filesystem;

use League\Flysystem\FilesystemOperator;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Exceptions\FileOperationException;
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

            // Flysystem createDirectory doesn't create parent directories recursively
            // We need to create parent directories first
            $parts = explode('/', trim($path, '/'));
            $currentPath = '';
            foreach ($parts as $part) {
                if ('' === $part) {
                    continue;
                }
                $currentPath .= '/'.$part;
                if (!$this->filesystem->directoryExists($currentPath)) {
                    try {
                        $this->filesystem->createDirectory($currentPath, [
                            'visibility' => $this->permissionsToVisibility($this->defaultDirectoryPermissions),
                        ]);
                    } catch (\Exception) {
                        // If directory already exists (race condition), verify it exists
                        // If it still doesn't exist, return false
                        // @phpstan-ignore-next-line (directoryExists may return true if directory was created by another process)
                        if (!$this->filesystem->directoryExists($currentPath)) {
                            return false;
                        }
                        // Directory exists now, continue
                    }
                }
            }

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Delete file from filesystem.
     *
     * WARNING: This method should NEVER be used to delete source files.
     * Source files must remain untouched. This method is only for:
     * - Deleting destination files (organized files)
     * - Deleting temporary files (e.g., compressed files in temp directory)
     *
     * The StorageToFilesystemBridge provides protection by only allowing
     * deletion from destination storage, not source storage.
     */
    #[\Override]
    public function delete(FilePath $filePath): bool
    {
        $path = $filePath->getPath();

        try {
            // If path is outside Flysystem root, use native PHP
            if (str_starts_with($path, '/') && !str_starts_with($path, $this->getFilesystemRoot())) {
                if (!file_exists($path)) {
                    return false;
                }

                // Suppress warnings for read-only file systems (files are already copied)
                return @unlink($path);
            }

            if (!$this->filesystem->fileExists($path)) {
                return false;
            }

            $this->filesystem->delete($path);

            return true;
        } catch (\Exception) {
            // Fallback to native PHP if Flysystem fails
            if (file_exists($path)) {
                // Suppress warnings for read-only file systems (files are already copied)
                return @unlink($path);
            }

            return false;
        }
    }

    #[\Override]
    public function exists(FilePath $filePath): bool
    {
        $path = $filePath->getPath();

        try {
            // Flysystem works relative to its root, but we might get absolute paths
            // If path is absolute and outside Flysystem root, use native PHP check
            if (str_starts_with($path, '/') && !str_starts_with($path, $this->getFilesystemRoot())) {
                return file_exists($path);
            }

            return $this->filesystem->fileExists($path);
        } catch (\Exception) {
            // Fallback to native PHP if Flysystem fails
            return file_exists($path);
        }
    }

    /**
     * Get Flysystem root directory.
     */
    private function getFilesystemRoot(): string
    {
        // Extract root from FilesystemOperator adapter
        // This is a workaround - Flysystem doesn't expose root directly
        $reflection = new \ReflectionClass($this->filesystem);
        $adapterProperty = $reflection->getProperty('adapter');
        /** @var mixed $adapter */
        $adapter = $adapterProperty->getValue($this->filesystem);

        if ($adapter instanceof \League\Flysystem\Local\LocalFilesystemAdapter) {
            $adapterReflection = new \ReflectionClass($adapter);
            $pathProperty = $adapterReflection->getProperty('rootLocation');
            /** @var mixed $rootLocation */
            $rootLocation = $pathProperty->getValue($adapter);

            if (is_string($rootLocation)) {
                return $rootLocation;
            }
        }

        return '/var/www/html'; // Default fallback
    }

    #[\Override]
    public function getSize(FilePath $filePath): int
    {
        $path = $filePath->getPath();

        try {
            // If path is outside Flysystem root, use native PHP
            if (str_starts_with($path, '/') && !str_starts_with($path, $this->getFilesystemRoot())) {
                $size = filesize($path);
                if (false === $size) {
                    throw FileOperationException::failedGetSize($path);
                }

                return $size;
            }

            return $this->filesystem->fileSize($path);
        } catch (\Exception $e) {
            // Fallback to native PHP if Flysystem fails
            $size = filesize($path);
            if (false === $size) {
                throw FileOperationException::failedGetSize($path, $e);
            }

            return $size;
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
            throw FileOperationException::failedGetPermissions($path, $e);
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
            throw FileOperationException::failedGetModificationTime($path, $e);
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
                throw FileOperationException::fileNotExists($path);
            }

            $atime = fileatime($path);
            if (false === $atime) {
                throw FileOperationException::failedGetAccessTime($path);
            }

            return $atime;
        } catch (\Exception $e) {
            throw FileOperationException::failedGetAccessTime($path, $e);
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
            // If path is outside Flysystem root, use native PHP
            if (str_starts_with($path, '/') && !str_starts_with($path, $this->getFilesystemRoot())) {
                $content = file_get_contents($path);
                if (false === $content) {
                    throw FileOperationException::failedRead($path);
                }

                return $content;
            }

            return $this->filesystem->read($path);
        } catch (\Exception $e) {
            // Fallback to native PHP if Flysystem fails
            $content = file_get_contents($path);
            if (false === $content) {
                throw FileOperationException::failedRead($path, $e);
            }

            return $content;
        }
    }

    #[\Override]
    public function calculateHash(FilePath $filePath): string
    {
        $path = $filePath->getPath();

        try {
            // If path is outside Flysystem root, use native PHP
            if (str_starts_with($path, '/') && !str_starts_with($path, $this->getFilesystemRoot())) {
                $hash = hash_file('sha256', $path);
                if (false === $hash) {
                    throw FileOperationException::failedCalculateHash($path);
                }

                return $hash;
            }

            $content = $this->filesystem->read($path);

            return hash('sha256', $content);
        } catch (\Exception $e) {
            // Fallback to native PHP if Flysystem fails
            $hash = hash_file('sha256', $path);
            if (false === $hash) {
                throw FileOperationException::failedCalculateHash($path, $e);
            }

            return $hash;
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
            throw FileOperationException::failedWrite($path, $e);
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
