<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

final readonly class MediaItem
{
    public function __construct(
        private string $id,
        private string $productUrl,
    ) {
        if ('' === $this->id) {
            throw new \InvalidArgumentException('Media item ID cannot be empty');
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getProductUrl(): string
    {
        return $this->productUrl;
    }
}
