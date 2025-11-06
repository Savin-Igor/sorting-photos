<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Filesystem;

use League\Flysystem\FilesystemOperator;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Service for copying files while preserving all metadata.
 * Uses Flysystem for filesystem operations but handles metadata preservation manually
 * since Flysystem doesn't preserve extended attributes and some metadata by default.
 */
final class MetadataPreservingCopier
{
    public function __construct(
        private readonly ?FilesystemOperator $filesystem = null,
    ) {
    }

    /**
     * Copy file preserving all metadata (permissions, timestamps, extended attributes).
     *
     * @param FilePath $source      Source file path
     * @param FilePath $destination Destination file path
     *
     * @return bool True on success
     *
     * @throws \InvalidArgumentException If source file does not exist
     * @throws \RuntimeException         If copy fails
     */
    public function copy(FilePath $source, FilePath $destination): bool
    {
        $sourcePath = $source->getPath();
        $destinationPath = $destination->getPath();

        if (!file_exists($sourcePath)) {
            throw new \InvalidArgumentException("Source file does not exist: {$sourcePath}");
        }

        // Ensure destination directory exists
        $destinationDir = dirname($destinationPath);
        if (!is_dir($destinationDir)) {
            if (!mkdir($destinationDir, 0755, true)) {
                throw new \RuntimeException("Failed to create destination directory: {$destinationDir}");
            }
        }

        // Get source metadata before copying
        $permissions = $this->getPermissions($sourcePath);
        $mtime = filemtime($sourcePath);
        if (false === $mtime) {
            throw new \RuntimeException("Failed to get source file modification time: {$sourcePath}");
        }

        $atime = fileatime($sourcePath);
        if (false === $atime) {
            $atime = $mtime;
        }

        // Copy file content
        if (null !== $this->filesystem) {
            // Use Flysystem if available
            try {
                $content = $this->filesystem->read($sourcePath);
                $this->filesystem->write($destinationPath, $content);
            } catch (\Exception $e) {
                // Fallback to native copy if Flysystem fails
                if (!copy($sourcePath, $destinationPath)) {
                    throw new \RuntimeException("Failed to copy file from {$sourcePath} to {$destinationPath}: {$e->getMessage()}");
                }
            }
        } else {
            // Use native PHP copy
            if (!copy($sourcePath, $destinationPath)) {
                throw new \RuntimeException("Failed to copy file from {$sourcePath} to {$destinationPath}");
            }
        }

        // Verify file integrity by comparing hashes
        $this->verifyIntegrity($sourcePath, $destinationPath);

        // Restore metadata
        $this->setPermissions($destinationPath, $permissions);
        touch($destinationPath, $mtime, $atime);

        // Copy extended attributes if supported
        $this->copyExtendedAttributes($sourcePath, $destinationPath);

        return true;
    }

    /**
     * Get file permissions.
     */
    private function getPermissions(string $filePath): int
    {
        $perms = fileperms($filePath);
        if (false === $perms) {
            throw new \RuntimeException("Failed to get file permissions: {$filePath}");
        }

        return $perms & 0777;
    }

    /**
     * Set file permissions.
     */
    private function setPermissions(string $filePath, int $permissions): void
    {
        if (!chmod($filePath, $permissions)) {
            throw new \RuntimeException("Failed to set file permissions: {$filePath}");
        }
    }

    /**
     * Verify file integrity by comparing SHA-256 hashes.
     */
    private function verifyIntegrity(string $sourcePath, string $destinationPath): void
    {
        $sourceHash = hash_file('sha256', $sourcePath);
        $destHash = hash_file('sha256', $destinationPath);

        if ($sourceHash !== $destHash) {
            // Clean up destination file if integrity check fails
            @unlink($destinationPath);
            throw new \RuntimeException("File integrity check failed: source hash {$sourceHash} does not match destination hash {$destHash}");
        }
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
            if (!is_array($attributes)) {
                return;
            }

            foreach ($attributes as $attr) {
                $value = xattr_get($source, $attr);
                if (false !== $value && is_string($value)) {
                    xattr_set($destination, $attr, $value);
                }
            }
        } catch (\Exception $e) {
            // Extended attributes not supported or error occurred - ignore
        }
    }
}
