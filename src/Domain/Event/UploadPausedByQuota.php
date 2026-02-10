<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Event;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJobId;

final class UploadPausedByQuota extends DomainEvent
{
    public function __construct(
        private readonly UploadJobId $jobId,
        private readonly \DateTimeImmutable $resetTime,
    ) {
        parent::__construct();
    }

    public function getJobId(): UploadJobId
    {
        return $this->jobId;
    }

    public function getResetTime(): \DateTimeImmutable
    {
        return $this->resetTime;
    }
}
