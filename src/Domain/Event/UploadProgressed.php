<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Event;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJobId;

final class UploadProgressed extends DomainEvent
{
    public function __construct(
        private readonly UploadJobId $jobId,
        private readonly int $uploadedBytes,
        private readonly int $totalBytes,
    ) {
        parent::__construct();
    }

    public function getJobId(): UploadJobId
    {
        return $this->jobId;
    }

    public function getUploadedBytes(): int
    {
        return $this->uploadedBytes;
    }

    public function getTotalBytes(): int
    {
        return $this->totalBytes;
    }

    public function getProgressPercent(): float
    {
        if (0 === $this->totalBytes) {
            return 0.0;
        }

        return ($this->uploadedBytes / $this->totalBytes) * 100.0;
    }
}
