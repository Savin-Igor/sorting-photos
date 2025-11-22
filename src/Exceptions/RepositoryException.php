<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Exceptions;

/**
 * Exception for repository and database operation errors.
 */
final class RepositoryException extends \RuntimeException
{
    private const string FAILED_SAVE = 'Failed to save entity: %s';
    private const string FAILED_SAVE_BATCH = 'Failed to save batch of %d entities: %s';
    private const string NO_WRITE_REPOSITORY = 'No write repository available for saving entity';

    public static function failedSave(string $entity, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_SAVE, $entity), 0, $previous);
    }

    public static function failedSaveBatch(int $count, string $error, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::FAILED_SAVE_BATCH, $count, $error), 0, $previous);
    }

    public static function noWriteRepository(): self
    {
        return new self(self::NO_WRITE_REPOSITORY);
    }
}
