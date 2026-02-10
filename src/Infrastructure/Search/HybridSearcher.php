<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Search;

use SortingPhotosByDate\Domain\Search\FileSearchCriteria;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Search\FileSearcherPort;
use SortingPhotosByDate\Ports\Search\SearcherNotAvailableException;

/**
 * Hybrid searcher that tries locate first, falls back to find.
 */
final class HybridSearcher implements FileSearcherPort
{
    private ?FileSearcherPort $activeSearcher = null;

    public function __construct(
        private readonly LocateSearcher $locateSearcher,
        private readonly FindCommandSearcher $findSearcher,
        private readonly LoggerPort $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        // Hybrid is available if at least one searcher is available
        return $this->locateSearcher->isAvailable() || $this->findSearcher->isAvailable();
    }

    public function getName(): string
    {
        return 'hybrid';
    }

    /**
     * @return iterable<FilePath>
     *
     * @throws SearcherNotAvailableException
     */
    public function search(string $directory, FileSearchCriteria $criteria): iterable
    {
        // Try locate first
        if ($this->locateSearcher->isAvailable()) {
            try {
                $this->activeSearcher = $this->locateSearcher;
                $this->logger->info('Using locate searcher', [
                    'directory' => $directory,
                ]);

                // Collect all results from locate to check if it's working
                // For external drives, locate may return 0 results even if files exist
                $results = [];
                foreach ($this->locateSearcher->search($directory, $criteria) as $filePath) {
                    $results[] = $filePath;
                }

                // If locate found files, yield them
                if (\count($results) > 0) {
                    foreach ($results as $filePath) {
                        yield $filePath;
                    }

                    return;
                }

                // If locate found 0 files, it might be an external drive not indexed
                // Fall back to find for better results
                $this->logger->warning('locate found 0 files, falling back to find (may be external drive not indexed)', [
                    'directory' => $directory,
                ]);
                // Fall through to find
            } catch (SearcherNotAvailableException $e) {
                $this->logger->warning('locate searcher failed, falling back to find', [
                    'error' => $e->getMessage(),
                ]);
                // Fall through to find
            }
        }

        // Fallback to find
        if ($this->findSearcher->isAvailable()) {
            $this->activeSearcher = $this->findSearcher;
            $this->logger->info('Using find searcher', [
                'directory' => $directory,
            ]);

            // Yield results from find searcher
            foreach ($this->findSearcher->search($directory, $criteria) as $filePath) {
                yield $filePath;
            }

            return;
        }

        throw SearcherNotAvailableException::noSearcherAvailable();
    }

    /**
     * Get currently active searcher name.
     */
    public function getActiveSearcherName(): ?string
    {
        return $this->activeSearcher instanceof FileSearcherPort ? $this->activeSearcher->getName() : null;
    }
}
