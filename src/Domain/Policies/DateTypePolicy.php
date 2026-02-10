<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Policies;

use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Exceptions\ValidationException;

final readonly class DateTypePolicy implements OrganizerPolicy
{
    public function __construct(
        private string $baseDirectory,
    ) {
        if ('' === $this->baseDirectory || '0' === $this->baseDirectory) {
            throw ValidationException::emptyValue('Base directory');
        }
    }

    #[\Override]
    public function organize(MediaAsset $asset): FilePath
    {
        $year = $asset->getDate()->getYear();
        $month = \str_pad((string) $asset->getDate()->getMonth(), 2, '0', STR_PAD_LEFT);
        $category = $asset->getCategory()->value;
        $fileName = $asset->getFileName();

        $targetPath = \sprintf(
            '%s/%d/%s/%s/%s',
            $this->baseDirectory,
            $year,
            $month,
            $category,
            $fileName
        );

        return new FilePath($targetPath);
    }
}
