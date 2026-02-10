<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Event;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Domain event: Error occurred during file processing.
 */
final class FileError extends DomainEvent
{
    public function __construct(
        private readonly FilePath $filePath,
        private readonly string $errorMessage,
        private readonly ?\Throwable $exception = null,
    ) {
        parent::__construct();
    }

    public function getFilePath(): FilePath
    {
        return $this->filePath;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }
}
