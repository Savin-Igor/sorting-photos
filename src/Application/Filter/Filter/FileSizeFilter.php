<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Filter\Filter;

use SortingPhotosByDate\Application\Filter\FileFilterInterface;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Filter by file size.
 */
final readonly class FileSizeFilter implements FileFilterInterface
{
    /**
     * @param int|null $minSizeBytes Minimum size in bytes (null = no limit)
     * @param int|null $maxSizeBytes Maximum size in bytes (null = no limit)
     */
    public function __construct(
        private ?int $minSizeBytes = null,
        private ?int $maxSizeBytes = null,
    ) {
    }

    #[\Override]
    public function shouldSkip(FilePath $filePath, array $context = []): bool
    {
        $fileSize = $context['file_size'] ?? null;
        if (null === $fileSize) {
            return false; // No size - skip filter
        }

        // Check minimum size
        if (null !== $this->minSizeBytes && $fileSize < $this->minSizeBytes) {
            return true; // File too small
        }

        // Check maximum size
        if (null !== $this->maxSizeBytes && $fileSize > $this->maxSizeBytes) {
            return true; // File too large
        }

        return false; // Allow processing
    }

    #[\Override]
    public function getName(): string
    {
        return 'file_size';
    }

    #[\Override]
    public function canFilterEarly(): bool
    {
        return false; // Needs file size from filesystem or metadata
    }
}
