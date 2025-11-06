<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Filesystem;

use League\Flysystem\FilesystemOperator;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Service for copying files while preserving all metadata.
 * Uses FilesystemOperator (Flysystem) directly to avoid circular dependency.
 * Extended attributes are handled separately since Flysystem doesn't support them.
 */
final readonly class MetadataPreservingCopier
{
    public function __construct(
        private FilesystemOperator $filesystem,
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
        $destPath = $destination->getPath();

        if (!$this->filesystem->fileExists($sourcePath)) {
            throw new \InvalidArgumentException("Source file does not exist: {$sourcePath}");
        }

        // Ensure destination directory exists
        $destinationDir = dirname($destPath);
        if (!$this->filesystem->directoryExists($destinationDir)) {
            $this->filesystem->createDirectory($destinationDir);
        }

        // Get source metadata before copying (using native PHP for metadata Flysystem doesn't support)
        $permissions = file_exists($sourcePath) ? fileperms($sourcePath) : 0644;
        $mtime = file_exists($sourcePath) ? filemtime($sourcePath) : time();
        $atime = file_exists($sourcePath) ? fileatime($sourcePath) : time();

        // Read source file content
        $content = $this->filesystem->read($sourcePath);

        // Write destination file
        $this->filesystem->write($destPath, $content);

        // Verify file integrity by comparing hashes
        $this->verifyIntegrity($sourcePath, $destPath);

        // Restore metadata (using native PHP for operations Flysystem doesn't support)
        if (file_exists($destPath)) {
            chmod($destPath, $permissions);
            touch($destPath, $mtime, $atime);
        }

        // Copy extended attributes if supported (requires native PHP)
        $this->copyExtendedAttributes($sourcePath, $destPath);

        return true;
    }

    /**
     * Verify file integrity by comparing SHA-256 hashes.
     */
    private function verifyIntegrity(string $sourcePath, string $destPath): void
    {
        $sourceContent = $this->filesystem->read($sourcePath);
        $destContent = $this->filesystem->read($destPath);

        $sourceHash = hash('sha256', $sourceContent);
        $destHash = hash('sha256', $destContent);

        if ($sourceHash !== $destHash) {
            // Clean up destination file if integrity check fails
            $this->filesystem->delete($destPath);
            throw new \RuntimeException("File integrity check failed: source hash {$sourceHash} does not match destination hash {$destHash}");
        }
    }

    /**
     * Copy extended attributes if supported by the filesystem.
     * Note: Extended attributes require native PHP functions as Flysystem doesn't support them.
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

            /** @var array<string> $attributes */
            foreach ($attributes as $attr) {
                /** @var string $attr */
                $value = xattr_get($source, $attr);
                /** @var string|false $value */
                if (false !== $value) {
                    xattr_set($destination, $attr, $value);
                }
            }
        } catch (\Exception) {
            // Extended attributes not supported or error occurred - ignore
        }
    }
}
