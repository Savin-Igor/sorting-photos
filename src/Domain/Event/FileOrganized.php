<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Event;

use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Domain event: File was successfully organized (moved to target location).
 */
final class FileOrganized extends DomainEvent
{
    public function __construct(
        private readonly MediaAsset $asset,
        private readonly FilePath $sourcePath,
        private readonly FilePath $targetPath,
    ) {
        parent::__construct();
    }

    public function getAsset(): MediaAsset
    {
        return $this->asset;
    }

    public function getSourcePath(): FilePath
    {
        return $this->sourcePath;
    }

    public function getTargetPath(): FilePath
    {
        return $this->targetPath;
    }
}
