<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Filter;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\LoggerPort;

/**
 * Chain of file filters.
 * If any filter returns true, file is skipped.
 */
final readonly class FilterChain
{
    /**
     * @param FileFilterInterface[] $filters Array of filters
     */
    public function __construct(
        private array $filters,
        private LoggerPort $logger,
    ) {
    }

    /**
     * Early filtering (before metadata extraction).
     * Used for filters that can work without metadata.
     *
     * @param FilePath             $filePath File path
     * @param array<string, mixed> $context  Context (may be empty for early filtering)
     *
     * @return bool true = skip file, false = allow processing
     */
    public function shouldSkipEarly(FilePath $filePath, array $context = []): bool
    {
        foreach ($this->filters as $filter) {
            // Check only filters that can work without metadata
            if ($filter->canFilterEarly() && $filter->shouldSkip($filePath, $context)) {
                $this->logger->info('File filtered out (early)', [
                    'file_path' => $filePath->getPath(),
                    'filter' => $filter->getName(),
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Full filtering (after metadata extraction).
     *
     * @param FilePath             $filePath File path
     * @param array<string, mixed> $context  Context with metadata
     *
     * @return bool true = skip file, false = allow processing
     */
    public function shouldSkip(FilePath $filePath, array $context = []): bool
    {
        foreach ($this->filters as $filter) {
            if ($filter->shouldSkip($filePath, $context)) {
                $this->logger->info('File filtered out', [
                    'file_path' => $filePath->getPath(),
                    'filter' => $filter->getName(),
                ]);

                return true;
            }
        }

        return false;
    }
}
