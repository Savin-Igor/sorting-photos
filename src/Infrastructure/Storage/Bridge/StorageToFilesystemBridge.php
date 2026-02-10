<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\Bridge;

use SortingPhotosByDate\Domain\Storage\StorageItem;
use SortingPhotosByDate\Domain\Storage\StorageMetadata;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Exceptions\FileOperationException;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\Storage\DestinationStoragePort;
use SortingPhotosByDate\Ports\Storage\StoragePort;

/**
 * Bridge adapter that implements FilesystemPort using StoragePort/DestinationStoragePort.
 * This allows existing code to work with the new storage abstraction.
 */
final readonly class StorageToFilesystemBridge implements FilesystemPort
{
    public function __construct(
        private StoragePort $sourceStorage,
        private DestinationStoragePort $destinationStorage,
    ) {
    }

    public function copyWithMetadata(FilePath $source, FilePath $destination): bool
    {
        $sourceItem = StorageItem::fromFilePath($source, $this->sourceStorage->getStorageType());
        $destItem = StorageItem::fromFilePath($destination, $this->destinationStorage->getStorageType());

        // Read from source
        $content = $this->sourceStorage->read($sourceItem);
        $metadata = $this->sourceStorage->getMetadata($sourceItem);

        // Write to destination
        return $this->destinationStorage->write($destItem, $content, $metadata);
    }

    public function ensureDirectory(FilePath $directory): bool
    {
        return $this->destinationStorage->ensureDirectory($directory->getPath());
    }

    public function delete(FilePath $filePath): bool
    {
        // NOTE: This method should NOT be used to delete source files.
        // Source files are NEVER deleted - only destination files can be deleted.
        // This method is kept for interface compatibility and cleanup of destination files only.

        // Try destination first (for organized files)
        $item = StorageItem::fromFilePath($filePath, $this->destinationStorage->getStorageType());
        if ($this->destinationStorage->exists($item)) {
            return $this->destinationStorage->delete($item);
        }

        // Do NOT delete from source storage - source files must remain untouched
        return false;
    }

    public function exists(FilePath $filePath): bool
    {
        $item = StorageItem::fromFilePath($filePath, $this->sourceStorage->getStorageType());

        return $this->sourceStorage->exists($item) || $this->destinationStorage->exists($item);
    }

    public function getSize(FilePath $filePath): int
    {
        $item = StorageItem::fromFilePath($filePath, $this->sourceStorage->getStorageType());

        // Get size from source storage only
        if (!$this->sourceStorage->exists($item)) {
            throw FileOperationException::fileNotExists($filePath->getPath());
        }

        $metadata = $this->sourceStorage->getMetadata($item);

        return $metadata->getSize() ?? 0;
    }

    public function getPermissions(FilePath $filePath): int
    {
        // Storage abstraction doesn't support permissions, return default
        return 0644;
    }

    public function setPermissions(FilePath $filePath, int $permissions): bool
    {
        // Storage abstraction doesn't support permissions
        return true;
    }

    public function getModificationTime(FilePath $filePath): int
    {
        $item = StorageItem::fromFilePath($filePath, $this->sourceStorage->getStorageType());

        // Get modification time from source storage only
        if (!$this->sourceStorage->exists($item)) {
            throw FileOperationException::fileNotExists($filePath->getPath());
        }

        $metadata = $this->sourceStorage->getMetadata($item);

        return $metadata->getModifiedTime() ?? time();
    }

    public function setModificationTime(FilePath $filePath, int $timestamp): bool
    {
        // Storage abstraction doesn't support setting modification time directly
        return true;
    }

    public function getAccessTime(FilePath $filePath): int
    {
        // Storage abstraction doesn't support access time
        return $this->getModificationTime($filePath);
    }

    public function setTimestamps(FilePath $filePath, int $mtime, int $atime): bool
    {
        // Storage abstraction doesn't support setting timestamps directly
        return true;
    }

    public function read(FilePath $filePath): string
    {
        $item = StorageItem::fromFilePath($filePath, $this->sourceStorage->getStorageType());

        // Read from source storage only
        // Note: Destination storage is write-only in our abstraction
        if (!$this->sourceStorage->exists($item)) {
            throw FileOperationException::fileNotExists($filePath->getPath());
        }

        return $this->sourceStorage->read($item);
    }

    public function calculateHash(FilePath $filePath): string
    {
        $content = $this->read($filePath);

        return hash('sha256', $content);
    }

    public function write(FilePath $filePath, string $content): void
    {
        $item = StorageItem::fromFilePath($filePath, $this->destinationStorage->getStorageType());
        $metadata = new StorageMetadata();

        if (!$this->destinationStorage->write($item, $content, $metadata)) {
            throw FileOperationException::failedWrite($filePath->getPath());
        }
    }
}
