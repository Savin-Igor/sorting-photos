<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Search;

use SortingPhotosByDate\Domain\Search\FileSearchCriteria;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Port for file searching functionality.
 * Provides fast file search using system tools (locate, find).
 */
interface FileSearcherPort
{
    /**
     * Search for files matching criteria in the given directory.
     *
     * @param string             $directory Directory to search in
     * @param FileSearchCriteria $criteria  Search criteria
     *
     * @return iterable<FilePath> Found file paths
     *
     * @throws SearcherNotAvailableException If searcher is not available
     */
    public function search(string $directory, FileSearchCriteria $criteria): iterable;

    /**
     * Check if searcher is available.
     */
    public function isAvailable(): bool;

    /**
     * Get searcher name for logging.
     */
    public function getName(): string;
}
