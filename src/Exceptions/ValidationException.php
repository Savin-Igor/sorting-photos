<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Exceptions;

/**
 * Exception for validation errors (invalid input parameters).
 */
final class ValidationException extends \InvalidArgumentException
{
    private const string EMPTY_VALUE = '%s cannot be empty';
    private const string NEGATIVE_VALUE = '%s must be non-negative';
    private const string POSITIVE_VALUE = '%s must be positive';
    private const string INVALID_RANGE = '%s cannot be greater than %s';
    private const string INVALID_REGEX = 'Invalid regex pattern "%s": %s';
    private const string INVALID_STATE_TRANSITION = 'Invalid state transition from %s to %s';
    private const string INVALID_INDEX = 'Invalid %s index: %d';
    private const string INVALID_TYPE = 'Unknown %s: %s';
    private const string INVALID_HEX_FORMAT = 'File hash must be hexadecimal';
    private const string HASH_LENGTH_OUT_OF_RANGE = 'Hash length must be between 1 and %d';
    private const string CANNOT_RESUME = 'Can only resume paused %s';
    private const string CANNOT_UNARCHIVE = 'Can only unarchive archived jobs';
    private const string BATCH_EMPTY = 'Batch must have at least one item';
    private const string CANNOT_CREATE_EMPTY_BATCH = 'Cannot create batch with empty items';
    private const string INDEX_OUT_OF_RANGE = 'Current index cannot exceed items count';
    private const string BYTES_CANNOT_DECREASE = 'Uploaded bytes cannot decrease';
    private const string MISSING_REQUIRED_FIELD = '%s must have %s to create %s';
    private const string CANNOT_UPDATE_PROGRESS = 'Cannot update progress: no resumable session';
    private const string FILE_NOT_EXISTS = 'File does not exist: %s';
    private const string DIRECTORY_NOT_EXISTS = 'Directory does not exist: %s';

    public static function emptyValue(string $fieldName): self
    {
        return new self(\sprintf(self::EMPTY_VALUE, $fieldName));
    }

    public static function negativeValue(string $fieldName): self
    {
        return new self(\sprintf(self::NEGATIVE_VALUE, $fieldName));
    }

    public static function positiveValue(string $fieldName): self
    {
        return new self(\sprintf(self::POSITIVE_VALUE, $fieldName));
    }

    public static function invalidRange(string $minField, string $maxField): self
    {
        return new self(\sprintf(self::INVALID_RANGE, $minField, $maxField));
    }

    public static function invalidRegex(string $pattern, string $error): self
    {
        return new self(\sprintf(self::INVALID_REGEX, $pattern, $error));
    }

    public static function invalidStateTransition(string $fromState, string $toState): self
    {
        return new self(\sprintf(self::INVALID_STATE_TRANSITION, $fromState, $toState));
    }

    public static function invalidIndex(string $fieldName, int $index): self
    {
        return new self(\sprintf(self::INVALID_INDEX, $fieldName, $index));
    }

    public static function invalidType(string $fieldName, string $value): self
    {
        return new self(\sprintf(self::INVALID_TYPE, $fieldName, $value));
    }

    public static function invalidHexFormat(): self
    {
        return new self(self::INVALID_HEX_FORMAT);
    }

    public static function hashLengthOutOfRange(int $maxLength): self
    {
        return new self(\sprintf(self::HASH_LENGTH_OUT_OF_RANGE, $maxLength));
    }

    public static function cannotResume(string $entityType): self
    {
        return new self(\sprintf(self::CANNOT_RESUME, $entityType));
    }

    public static function cannotUnarchive(): self
    {
        return new self(self::CANNOT_UNARCHIVE);
    }

    public static function batchEmpty(): self
    {
        return new self(self::BATCH_EMPTY);
    }

    public static function cannotCreateEmptyBatch(): self
    {
        return new self(self::CANNOT_CREATE_EMPTY_BATCH);
    }

    public static function indexOutOfRange(): self
    {
        return new self(self::INDEX_OUT_OF_RANGE);
    }

    public static function bytesCannotDecrease(): self
    {
        return new self(self::BYTES_CANNOT_DECREASE);
    }

    public static function missingRequiredField(string $entityType, string $fieldName, string $action): self
    {
        return new self(\sprintf(self::MISSING_REQUIRED_FIELD, $entityType, $fieldName, $action));
    }

    public static function cannotUpdateProgress(): self
    {
        return new self(self::CANNOT_UPDATE_PROGRESS);
    }

    public static function fileNotExists(string $filePath): self
    {
        return new self(\sprintf(self::FILE_NOT_EXISTS, $filePath));
    }

    public static function directoryNotExists(string $directory): self
    {
        return new self(\sprintf(self::DIRECTORY_NOT_EXISTS, $directory));
    }
}
