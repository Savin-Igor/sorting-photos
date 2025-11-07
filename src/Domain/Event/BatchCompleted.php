<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Event;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatchId;

final class BatchCompleted extends DomainEvent
{
    public function __construct(
        private readonly UploadBatchId $batchId,
        private readonly int $successfulItems,
        private readonly int $totalItems,
    ) {
        parent::__construct();
    }

    public function getBatchId(): UploadBatchId
    {
        return $this->batchId;
    }

    public function getSuccessfulItems(): int
    {
        return $this->successfulItems;
    }

    public function getTotalItems(): int
    {
        return $this->totalItems;
    }
}
