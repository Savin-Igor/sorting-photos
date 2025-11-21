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

                return $this->locateSearcher->search($directory, $criteria);
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

            return $this->findSearcher->search($directory, $criteria);
        }

        throw new SearcherNotAvailableException('Neither locate nor find searcher is available');
    }

    /**
     * Get currently active searcher name.
     */
    public function getActiveSearcherName(): ?string
    {
        return $this->activeSearcher instanceof FileSearcherPort ? $this->activeSearcher->getName() : null;
    }
}
