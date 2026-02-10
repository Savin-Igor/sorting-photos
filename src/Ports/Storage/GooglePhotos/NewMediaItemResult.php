<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

final readonly class NewMediaItemResult
{
    public function __construct(
        private string $uploadToken,
        private Status $status,
        private ?MediaItem $mediaItem = null,
    ) {
    }

    public function getUploadToken(): string
    {
        return $this->uploadToken;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function getMediaItem(): ?MediaItem
    {
        return $this->mediaItem;
    }
}
