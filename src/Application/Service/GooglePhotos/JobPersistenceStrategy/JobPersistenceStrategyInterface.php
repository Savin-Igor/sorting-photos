<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos\JobPersistenceStrategy;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;

/**
 * Strategy interface for persisting upload jobs.
 * Allows different persistence strategies (single, batch, etc.).
 */
interface JobPersistenceStrategyInterface
{
    /**
     * Persist a single job.
     * May buffer jobs for batch operations or save immediately.
     *
     * @param UploadJob $job Job to persist
     */
    public function persist(UploadJob $job): void;

    /**
     * Flush any buffered jobs to storage.
     * For batch strategies, this saves accumulated jobs.
     * For single strategies, this is a no-op.
     *
     * @return int Number of jobs flushed
     */
    public function flush(): int;

    /**
     * Check if strategy supports batch operations.
     *
     * @return bool True if strategy buffers jobs for batch save
     */
    public function supportsBatch(): bool;
}
