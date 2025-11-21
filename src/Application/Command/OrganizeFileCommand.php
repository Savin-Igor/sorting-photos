<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Command;

use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Command to organize a file: apply policy, copy with metadata, verify hash.
 * Source files are NEVER deleted - only copied to destination.
 */
final readonly class OrganizeFileCommand
{
    public function __construct(
        private MediaAsset $asset,
        private FilePath $destinationBasePath,
    ) {
    }

    public function getAsset(): MediaAsset
    {
        return $this->asset;
    }

    public function getDestinationBasePath(): FilePath
    {
        return $this->destinationBasePath;
    }
}
