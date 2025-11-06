<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Port for filesystem operations.
 */
interface FilesystemPort
{
    /**
     * Copy file preserving all metadata (permissions, timestamps, extended attributes).
     *
     * @param FilePath $source      Source file path
     * @param FilePath $destination Destination file path
     *
     * @return bool True on success
     */
    public function copyWithMetadata(FilePath $source, FilePath $destination): bool;

    /**
     * Create directory if it doesn't exist.
     *
     * @param FilePath $directory Directory path
     *
     * @return bool True on success
     */
    public function ensureDirectory(FilePath $directory): bool;

    /**
     * Delete file.
     *
     * @param FilePath $filePath File path to delete
     *
     * @return bool True on success
     */
    public function delete(FilePath $filePath): bool;

    /**
     * Check if file exists.
     *
     * @param FilePath $filePath File path to check
     *
     * @return bool True if exists
     */
    public function exists(FilePath $filePath): bool;

    /**
     * Get file size in bytes.
     *
     * @param FilePath $filePath File path
     *
     * @return int File size in bytes
     */
    public function getSize(FilePath $filePath): int;

    /**
     * Get file permissions.
     *
     * @param FilePath $filePath File path
     *
     * @return int File permissions (octal)
     */
    public function getPermissions(FilePath $filePath): int;

    /**
     * Set file permissions.
     *
     * @param FilePath $filePath    File path
     * @param int      $permissions Permissions (octal)
     *
     * @return bool True on success
     */
    public function setPermissions(FilePath $filePath, int $permissions): bool;

    /**
     * Get file modification time.
     *
     * @param FilePath $filePath File path
     *
     * @return int Unix timestamp
     */
    public function getModificationTime(FilePath $filePath): int;

    /**
     * Set file modification time.
     *
     * @param FilePath $filePath  File path
     * @param int      $timestamp Unix timestamp
     *
     * @return bool True on success
     */
    public function setModificationTime(FilePath $filePath, int $timestamp): bool;
}
