<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos\JobPersistenceStrategy;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;

/**
 * Single job persistence strategy that saves jobs immediately.
 * Used when batch operations are not needed or not supported.
 */
final readonly class SingleJobPersistenceStrategy implements JobPersistenceStrategyInterface
{
    public function __construct(
        private UploadJobRepositoryPort $repository,
    ) {
    }

    public function persist(UploadJob $job): void
    {
        $this->repository->save($job);
    }

    public function flush(): int
    {
        // No-op for single strategy - jobs are saved immediately
        return 0;
    }

    public function supportsBatch(): bool
    {
        return false;
    }
}
