<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Command;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Command to ingest a file: detect MIME type, extract metadata, calculate hash.
 */
final readonly class IngestFileCommand
{
    public function __construct(
        private FilePath $filePath,
    ) {
    }

    public function getFilePath(): FilePath
    {
        return $this->filePath;
    }
}
