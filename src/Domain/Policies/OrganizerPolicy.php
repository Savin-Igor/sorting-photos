<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Policies;

use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

interface OrganizerPolicy
{
    public function organize(MediaAsset $asset): FilePath;
}
