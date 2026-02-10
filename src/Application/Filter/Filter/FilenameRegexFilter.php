<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Filter\Filter;

use SortingPhotosByDate\Application\Filter\FileFilterInterface;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Filter by filename regex patterns.
 * Useful for filtering screenshots, specific naming patterns, etc.
 */
final readonly class FilenameRegexFilter implements FileFilterInterface
{
    /**
     * @param array<string> $patterns Regex patterns ['/screenshot/i', '/screen.?shot/i']
     */
    public function __construct(
        private array $patterns,
    ) {
    }

    #[\Override]
    public function shouldSkip(FilePath $filePath, array $context = []): bool
    {
        $filename = \basename($filePath->getPath());
        $fullPath = $filePath->getPath();

        foreach ($this->patterns as $pattern) {
            // Check filename
            if (\preg_match($pattern, $filename)) {
                return true; // Matched pattern - skip
            }

            // Check full path (optional, for more flexible filtering)
            if (\preg_match($pattern, $fullPath)) {
                return true;
            }
        }

        return false; // Allow processing
    }

    #[\Override]
    public function getName(): string
    {
        return 'filename_regex';
    }

    #[\Override]
    public function canFilterEarly(): bool
    {
        return true; // Can work without metadata
    }
}
