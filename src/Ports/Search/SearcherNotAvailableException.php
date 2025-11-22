<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Search;

/**
 * Exception thrown when file searcher is not available.
 */
final class SearcherNotAvailableException extends \RuntimeException
{
    private const string COMMAND_NOT_AVAILABLE = '%s command is not available';
    private const string COMMAND_FAILED = '%s command failed: %s';
    private const string NO_SEARCHER_AVAILABLE = 'Neither locate nor find searcher is available';

    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function commandNotAvailable(string $command): self
    {
        return new self(\sprintf(self::COMMAND_NOT_AVAILABLE, $command));
    }

    public static function commandFailed(string $command, string $error, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(self::COMMAND_FAILED, $command, $error), $previous);
    }

    public static function noSearcherAvailable(): self
    {
        return new self(self::NO_SEARCHER_AVAILABLE);
    }
}
