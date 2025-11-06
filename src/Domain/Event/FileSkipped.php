<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Event;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Domain event: File was skipped (duplicate, already processed, etc.).
 */
final class FileSkipped extends DomainEvent
{
    public function __construct(
        private readonly FilePath $filePath,
        private readonly string $reason,
        private readonly ?string $existingFilePath = null,
    ) {
        parent::__construct();
    }

    public function getFilePath(): FilePath
    {
        return $this->filePath;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getExistingFilePath(): ?string
    {
        return $this->existingFilePath;
    }
}
