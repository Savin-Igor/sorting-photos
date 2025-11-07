<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Event;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJobId;

final class UploadCompleted extends DomainEvent
{
    public function __construct(
        private readonly UploadJobId $jobId,
        private readonly string $mediaItemId,
    ) {
        parent::__construct();
    }

    public function getJobId(): UploadJobId
    {
        return $this->jobId;
    }

    public function getMediaItemId(): string
    {
        return $this->mediaItemId;
    }
}
