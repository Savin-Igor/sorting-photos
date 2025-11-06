<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Policies;

use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

final class DatePolicy implements OrganizerPolicy
{
    public function __construct(
        private readonly string $baseDirectory,
    ) {
        if (empty($this->baseDirectory)) {
            throw new \InvalidArgumentException('Base directory cannot be empty');
        }
    }

    #[\Override]
    public function organize(MediaAsset $asset): FilePath
    {
        $year = $asset->getDate()->getYear();
        $month = \str_pad((string) $asset->getDate()->getMonth(), 2, '0', STR_PAD_LEFT);
        $fileName = $asset->getFileName();

        $targetPath = \sprintf(
            '%s/%d/%s/%s',
            $this->baseDirectory,
            $year,
            $month,
            $fileName
        );

        return new FilePath($targetPath);
    }
}
