<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

use SortingPhotosByDate\Exceptions\ValidationException;

final readonly class MediaItem
{
    public function __construct(
        private string $id,
        private string $productUrl,
    ) {
        if ('' === $this->id) {
            throw ValidationException::emptyValue('Media item ID');
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
