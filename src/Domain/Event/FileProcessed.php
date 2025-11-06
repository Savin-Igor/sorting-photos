<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Event;

use SortingPhotosByDate\Domain\MediaAsset;

/**
 * Domain event: File was successfully processed (metadata extracted, hash calculated).
 */
final class FileProcessed extends DomainEvent
{
    public function __construct(
        private readonly MediaAsset $asset,
    ) {
        parent::__construct();
    }

    public function getAsset(): MediaAsset
    {
        return $this->asset;
    }
}
