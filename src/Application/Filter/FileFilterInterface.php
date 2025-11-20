<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Filter;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Interface for file filters.
 * Filters determine whether a file should be excluded from processing.
 */
interface FileFilterInterface
{
    /**
     * Checks if file should be skipped (excluded from processing).
     *
     * @param FilePath             $filePath File path
     * @param array<string, mixed> $context  Context (mime_type, file_size, is_video, metadata, creation_date, etc.)
     *
     * @return bool true = skip file, false = allow processing
     */
    public function shouldSkip(FilePath $filePath, array $context = []): bool;

    /**
     * Returns filter name for logging.
     */
    public function getName(): string;

    /**
     * Checks if filter can work without metadata (early filtering).
     * Used to optimize scanning by filtering files before metadata extraction.
     */
    public function canFilterEarly(): bool;
}
