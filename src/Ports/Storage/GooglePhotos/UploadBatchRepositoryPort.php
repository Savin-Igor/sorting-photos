<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatchId;

interface UploadBatchRepositoryPort
{
    public function save(UploadBatch $batch): void;

    public function findById(UploadBatchId $id): ?UploadBatch;

    public function findProcessingOrPaused(): ?UploadBatch;

    /**
     * @return array<UploadBatch>
     */
    public function findPausedReadyToResume(\DateTimeImmutable $now): array;
}
