<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatchId;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJobId;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;

interface UploadJobRepositoryPort
{
    public function save(UploadJob $job): void;

    public function findById(UploadJobId $id): ?UploadJob;

    public function findByHash(FileHash $hash): ?UploadJob;

    public function findNextPendingOrResumable(): ?UploadJob;

    /**
     * @return array<UploadJob>
     */
    public function findReadyForBatch(int $limit): array;

    /**
     * Find ready jobs filtered by file size.
     *
     * @param int $maxSize Maximum file size in bytes (exclusive). Files smaller than this will be returned.
     * @param int $limit   Maximum number of jobs to return
     *
     * @return array<UploadJob>
     */
    public function findReadyForBatchBySize(int $maxSize, int $limit): array;

    /**
     * @return array<UploadJob>
     */
    public function findWithExpiredSessions(): array;

    /**
     * @return array<UploadJob>
     */
    public function findByBatchId(UploadBatchId $batchId): array;

    /**
     * @return array<UploadJob>
     */
    public function findPausedReadyToResume(\DateTimeImmutable $now): array;
}
