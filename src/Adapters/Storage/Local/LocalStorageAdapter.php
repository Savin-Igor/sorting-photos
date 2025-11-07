<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Storage\Local;

use SortingPhotosByDate\Domain\Storage\StorageItem;
use SortingPhotosByDate\Domain\Storage\StorageMetadata;
use SortingPhotosByDate\Domain\Storage\StorageType;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Storage\Attribute\StorageAdapter;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\ScannerPort;
use SortingPhotosByDate\Ports\Storage\DestinationStoragePort;
use SortingPhotosByDate\Ports\Storage\StoragePort;

/**
 * Local filesystem storage adapter.
 * Wraps existing FilesystemPort and ScannerPort for compatibility.
 */
#[StorageAdapter(type: StorageType::LOCAL)]
final readonly class LocalStorageAdapter implements StoragePort, DestinationStoragePort
{
    public function __construct(
        private FilesystemPort $filesystem,
        private ScannerPort $scanner,
        private string $basePath,
    ) {
        if (!is_dir($this->basePath)) {
            throw new \InvalidArgumentException("Base path does not exist: {$this->basePath}");
        }
    }

    public function scan(string $location): iterable
    {
        $fullPath = $this->resolvePath($location);

        foreach ($this->scanner->scan($fullPath) as $filePath) {
            yield StorageItem::fromFilePath($filePath, StorageType::LOCAL)
                ->withSize($this->filesystem->getSize($filePath))
                ->withModifiedTime($this->filesystem->getModificationTime($filePath));
        }
    }

    public function read(StorageItem $item): string
    {
        $filePath = $this->itemToFilePath($item);

        return $this->filesystem->read($filePath);
    }

    public function getMetadata(StorageItem $item): StorageMetadata
    {
        $filePath = $this->itemToFilePath($item);

        return new StorageMetadata(
            size: $this->filesystem->getSize($filePath),
            modifiedTime: $this->filesystem->getModificationTime($filePath),
            createdTime: $this->filesystem->getAccessTime($filePath), // Fallback, local FS doesn't track creation time
        );
    }

    public function exists(StorageItem $item): bool
    {
        $filePath = $this->itemToFilePath($item);

        return $this->filesystem->exists($filePath);
    }

    public function write(StorageItem $item, string $content, StorageMetadata $metadata): bool
    {
        $filePath = $this->itemToFilePath($item);

        // Ensure directory exists
        $directory = new FilePath($filePath->getDirectory());
        if (!$this->filesystem->ensureDirectory($directory)) {
            return false;
        }

        // Write content
        try {
            $this->filesystem->write($filePath, $content);
        } catch (\RuntimeException) {
            return false;
        }

        // Apply metadata if provided
        if (null !== $metadata->getModifiedTime()) {
            $this->filesystem->setModificationTime($filePath, $metadata->getModifiedTime());
        }
        if (null !== $metadata->getCreatedTime()) {
            $this->filesystem->setTimestamps($filePath, $metadata->getModifiedTime() ?? time(), $metadata->getCreatedTime());
        }

        return true;
    }

    public function delete(StorageItem $item): bool
    {
        $filePath = $this->itemToFilePath($item);

        return $this->filesystem->delete($filePath);
    }

    public function ensureDirectory(string $path): bool
    {
        $fullPath = $this->resolvePath($path);
        $directory = new FilePath($fullPath);

        return $this->filesystem->ensureDirectory($directory);
    }

    public function getStorageType(): StorageType
    {
        return StorageType::LOCAL;
    }

    /**
     * Resolve relative path to full path.
     */
    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($this->basePath, '/').'/'.ltrim($path, '/');
    }

    /**
     * Convert StorageItem to FilePath.
     */
    private function itemToFilePath(StorageItem $item): FilePath
    {
        // If item path is absolute and exists, use it directly
        if (str_starts_with($item->getPath(), '/')) {
            return new FilePath($item->getPath());
        }

        // Otherwise resolve relative to base path
        return new FilePath($this->resolvePath($item->getPath()));
    }
}
