<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Event;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Domain event: File was discovered during scanning.
 */
final class FileDiscovered extends DomainEvent
{
    public function __construct(
        private readonly FilePath $filePath,
        private readonly int $fileSize,
    ) {
        parent::__construct();
    }

    public function getFilePath(): FilePath
    {
        return $this->filePath;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }
}
