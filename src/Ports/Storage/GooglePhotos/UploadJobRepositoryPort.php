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
