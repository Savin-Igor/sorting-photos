<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Filesystem;

use League\Flysystem\FilesystemOperator;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Service for copying files while preserving all metadata.
 * Uses FilesystemOperator (Flysystem) directly to avoid circular dependency.
 * Extended attributes are handled separately since Flysystem doesn't support them.
 * For files outside Flysystem root, falls back to native PHP functions.
 */
final readonly class MetadataPreservingCopier
{
    public function __construct(
        private FilesystemOperator $filesystem,
    ) {
    }

    /**
     * Get Flysystem root directory.
     */
    private function getFilesystemRoot(): string
    {
        try {
            // Extract root from FilesystemOperator adapter
            $reflection = new \ReflectionClass($this->filesystem);

            // Check if adapter property exists (may not exist in mocks)
            if (!$reflection->hasProperty('adapter')) {
                // For mocks, return empty string to force Flysystem path handling
                return '';
            }

            $adapterProperty = $reflection->getProperty('adapter');
            $adapter = $adapterProperty->getValue($this->filesystem);

            if ($adapter instanceof \League\Flysystem\Local\LocalFilesystemAdapter) {
                $adapterReflection = new \ReflectionClass($adapter);

                // Check if rootLocation property exists
                if (!$adapterReflection->hasProperty('rootLocation')) {
                    return '/var/www/html'; // Default fallback
                }

                $pathProperty = $adapterReflection->getProperty('rootLocation');
                $rootLocation = $pathProperty->getValue($adapter);

                if (is_string($rootLocation)) {
                    return $rootLocation;
                }
            }
        } catch (\ReflectionException) {
            // Fallback for mocks or when reflection fails - return empty to force Flysystem handling
            return '';
        }

        return '/var/www/html'; // Default fallback
    }

    /**
     * Check if path is within Flysystem root.
     */
    private function isWithinFilesystemRoot(string $path): bool
    {
        $root = $this->getFilesystemRoot();

        // If root is empty (mocks), always use Flysystem methods
        if ('' === $root) {
            return true;
        }

        return str_starts_with($path, $root);
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

        // Check if files are within Flysystem root
        $sourceInRoot = $this->isWithinFilesystemRoot($sourcePath);
        $destInRoot = $this->isWithinFilesystemRoot($destPath);

        // If both files are outside Flysystem root, use native PHP copy
        if (!$sourceInRoot && !$destInRoot) {
            return $this->copyWithNativePHP($sourcePath, $destPath);
        }

        // If source is outside root but destination is inside, read with native PHP, write with Flysystem
        if (!$sourceInRoot) {
            return $this->copyFromNativeToFlysystem($sourcePath, $destPath);
        }

        // Standard Flysystem copy (both files within root)
        if (!$this->filesystem->fileExists($sourcePath)) {
            throw new \InvalidArgumentException("Source file does not exist: {$sourcePath}");
        }

        // Ensure destination directory exists
        $destinationDir = dirname($destPath);
        if (!$this->filesystem->directoryExists($destinationDir)) {
            $this->filesystem->createDirectory($destinationDir);
        }

        // Get source metadata before copying (using native PHP for metadata Flysystem doesn't support)
        $permissions = 0644;
        $mtime = time();
        $atime = time();
        if (file_exists($sourcePath)) {
            $perms = fileperms($sourcePath);
            $mtimeResult = filemtime($sourcePath);
            $atimeResult = fileatime($sourcePath);
            if (false !== $perms) {
                $permissions = $perms;
            }
            if (false !== $mtimeResult) {
                $mtime = $mtimeResult;
            }
            if (false !== $atimeResult) {
                $atime = $atimeResult;
            }
        }

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
     * Copy file using native PHP functions (for files outside Flysystem root).
     */
    private function copyWithNativePHP(string $sourcePath, string $destPath): bool
    {
        if (!file_exists($sourcePath)) {
            throw new \InvalidArgumentException("Source file does not exist: {$sourcePath}");
        }

        // Ensure destination directory exists
        $destinationDir = dirname($destPath);
        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }

        // Get source metadata
        $permissions = fileperms($sourcePath) ?: 0644;
        $mtime = filemtime($sourcePath) ?: time();
        $atime = fileatime($sourcePath) ?: time();

        // Copy file
        if (!copy($sourcePath, $destPath)) {
            throw new \RuntimeException("Failed to copy file from {$sourcePath} to {$destPath}");
        }

        // Restore metadata
        chmod($destPath, $permissions);
        touch($destPath, $mtime, $atime);

        // Verify integrity
        $this->verifyIntegrityNative($sourcePath, $destPath);

        // Copy extended attributes
        $this->copyExtendedAttributes($sourcePath, $destPath);

        return true;
    }

    /**
     * Copy from native filesystem to Flysystem.
     */
    private function copyFromNativeToFlysystem(string $sourcePath, string $destPath): bool
    {
        if (!file_exists($sourcePath)) {
            throw new \InvalidArgumentException("Source file does not exist: {$sourcePath}");
        }

        // Ensure destination directory exists
        $destinationDir = dirname($destPath);
        if (!$this->filesystem->directoryExists($destinationDir)) {
            $this->filesystem->createDirectory($destinationDir);
        }

        // Get source metadata
        $permissions = fileperms($sourcePath) ?: 0644;
        $mtime = filemtime($sourcePath) ?: time();
        $atime = fileatime($sourcePath) ?: time();

        // Read source with native PHP
        $content = file_get_contents($sourcePath);
        if (false === $content) {
            throw new \RuntimeException("Failed to read source file: {$sourcePath}");
        }

        // Write destination with Flysystem
        $this->filesystem->write($destPath, $content);

        // Verify integrity
        $destContent = $this->filesystem->read($destPath);
        $sourceHash = hash('sha256', $content);
        $destHash = hash('sha256', $destContent);
        if ($sourceHash !== $destHash) {
            $this->filesystem->delete($destPath);
            throw new \RuntimeException("File integrity check failed: source hash {$sourceHash} does not match destination hash {$destHash}");
        }

        // Restore metadata using native PHP (Flysystem doesn't support setting timestamps)
        if (file_exists($destPath)) {
            chmod($destPath, $permissions);
            touch($destPath, $mtime, $atime);
        }

        // Copy extended attributes
        $this->copyExtendedAttributes($sourcePath, $destPath);

        return true;
    }

    /**
     * Verify file integrity using native PHP (for files outside Flysystem root).
     */
    private function verifyIntegrityNative(string $sourcePath, string $destPath): void
    {
        $sourceHash = hash_file('sha256', $sourcePath);
        $destHash = hash_file('sha256', $destPath);

        if ($sourceHash !== $destHash) {
            if (file_exists($destPath)) {
                unlink($destPath);
            }
            throw new \RuntimeException("File integrity check failed: source hash {$sourceHash} does not match destination hash {$destHash}");
        }
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

            /** @var array<int, string> $attributesArray */
            $attributesArray = $attributes;
            foreach ($attributesArray as $attr) {
                // $attr is string due to type annotation above
                $value = xattr_get($source, $attr);
                if (is_string($value)) {
                    xattr_set($destination, $attr, $value);
                }
            }
        } catch (\Exception) {
            // Extended attributes not supported or error occurred - ignore
        }
    }
}
