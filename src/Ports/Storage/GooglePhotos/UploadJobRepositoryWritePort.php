<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;

/**
 * Write-only interface for UploadJob repository.
 * Separates write operations from read operations following Interface Segregation Principle.
 */
interface UploadJobRepositoryWritePort
{
    public function save(UploadJob $job): void;

    /**
     * Save multiple jobs in a single batch operation.
     * Uses transaction for atomicity - all jobs are saved or none.
     *
     * @param array<UploadJob> $jobs Jobs to save
     *
     * @return int Number of jobs successfully saved
     */
    public function saveBatch(array $jobs): int;
}
