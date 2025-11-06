<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Filesystem;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\FilesystemPort;

/**
 * Service for copying files while preserving all metadata.
 * Uses FilesystemPort (Flysystem) for all filesystem operations.
 * Extended attributes are handled separately since Flysystem doesn't support them.
 */
final readonly class MetadataPreservingCopier
{
    public function __construct(
        private FilesystemPort $filesystem,
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
        if (!$this->filesystem->exists($source)) {
            throw new \InvalidArgumentException("Source file does not exist: {$source->getPath()}");
        }

        // Ensure destination directory exists
        $destinationDir = new FilePath(dirname($destination->getPath()));
        $this->filesystem->ensureDirectory($destinationDir);

        // Get source metadata before copying
        $permissions = $this->filesystem->getPermissions($source);
        $mtime = $this->filesystem->getModificationTime($source);
        $atime = $this->filesystem->getAccessTime($source);

        // Read source file content
        $content = $this->filesystem->read($source);

        // Write destination file
        $this->filesystem->write($destination, $content);

        // Verify file integrity by comparing hashes
        $this->verifyIntegrity($source, $destination);

        // Restore metadata
        $this->filesystem->setPermissions($destination, $permissions);
        $this->filesystem->setTimestamps($destination, $mtime, $atime);

        // Copy extended attributes if supported (requires native PHP)
        $this->copyExtendedAttributes($source->getPath(), $destination->getPath());

        return true;
    }

    /**
     * Verify file integrity by comparing SHA-256 hashes.
     */
    private function verifyIntegrity(FilePath $sourcePath, FilePath $destinationPath): void
    {
        $sourceHash = $this->filesystem->calculateHash($sourcePath);
        $destHash = $this->filesystem->calculateHash($destinationPath);

        if ($sourceHash !== $destHash) {
            // Clean up destination file if integrity check fails
            $this->filesystem->delete($destinationPath);
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
