<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Event;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatchId;

final class BatchCreated extends DomainEvent
{
    public function __construct(
        private readonly UploadBatchId $batchId,
        private readonly int $itemCount,
    ) {
        parent::__construct();
    }

    public function getBatchId(): UploadBatchId
    {
        return $this->batchId;
    }

    public function getItemCount(): int
    {
        return $this->itemCount;
    }
}
