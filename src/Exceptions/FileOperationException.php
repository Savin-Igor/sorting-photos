<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Exceptions;

/**
 * Exception for file system operation errors.
 */
final class FileOperationException extends \RuntimeException
{
    private const string FILE_NOT_EXISTS = 'File does not exist: %s';
    private const string SOURCE_FILE_NOT_EXISTS = 'Source file does not exist: %s';
    private const string DIRECTORY_NOT_EXISTS = 'Directory does not exist: %s';
    private const string FAILED_READ = 'Failed to read file: %s';
    private const string FAILED_WRITE = 'Failed to write file: %s';
    private const string FAILED_COPY = 'Failed to copy file from %s to %s';
    private const string FAILED_GET_SIZE = 'Failed to get file size: %s';
    private const string FAILED_GET_PERMISSIONS = 'Failed to get file permissions: %s';
    private const string FAILED_GET_MODIFICATION_TIME = 'Failed to get file modification time: %s';
    private const string FAILED_GET_ACCESS_TIME = 'Failed to get file access time: %s';
    private const string FAILED_CREATE_DIRECTORY = 'Failed to create directory: %s';
    private const string FAILED_CALCULATE_HASH = 'Failed to calculate hash for file: %s';
    private const string HASH_MISMATCH = 'File integrity check failed: source hash %s does not match destination hash %s';
    private const string HASH_MISMATCH_EXPECTED = 'File integrity check failed: expected hash %s does not match target hash %s';

    public static function fileNotExists(string $filePath): self
    {
        return new self(\sprintf(self::FILE_NOT_EXISTS, $filePath));
    }

    public static function sourceFileNotExists(string $filePath): self
    {
        return new self(\sprintf(self::SOURCE_FILE_NOT_EXISTS, $filePath));
    }

    public static function directoryNotExists(string $directory): self
    {
        return new self(\sprintf(self::DIRECTORY_NOT_EXISTS, $directory));
    }

    public static function failedRead(string $filePath, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_READ, $filePath), 0, $previous);
    }

    public static function failedWrite(string $filePath, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_WRITE, $filePath), 0, $previous);
    }

    public static function failedCopy(string $sourcePath, string $destPath, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_COPY, $sourcePath, $destPath), 0, $previous);
    }

    public static function failedGetSize(string $filePath, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_GET_SIZE, $filePath), 0, $previous);
    }

    public static function failedGetPermissions(string $filePath, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_GET_PERMISSIONS, $filePath), 0, $previous);
    }

    public static function failedGetModificationTime(string $filePath, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_GET_MODIFICATION_TIME, $filePath), 0, $previous);
    }

    public static function failedGetAccessTime(string $filePath, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_GET_ACCESS_TIME, $filePath), 0, $previous);
    }

    public static function failedCreateDirectory(string $directory, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_CREATE_DIRECTORY, $directory), 0, $previous);
    }

    public static function failedCalculateHash(string $filePath, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_CALCULATE_HASH, $filePath), 0, $previous);
    }

    public static function hashMismatch(string $sourceHash, string $destHash): self
    {
        return new self(\sprintf(self::HASH_MISMATCH, $sourceHash, $destHash));
    }

    public static function hashMismatchExpected(string $expectedHash, string $targetHash): self
    {
        return new self(\sprintf(self::HASH_MISMATCH_EXPECTED, $expectedHash, $targetHash));
    }
}
