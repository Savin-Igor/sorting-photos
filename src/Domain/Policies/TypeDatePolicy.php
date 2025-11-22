<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Policies;

use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Exceptions\ValidationException;

final readonly class TypeDatePolicy implements OrganizerPolicy
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
        $category = $asset->getCategory()->value;
        $year = $asset->getDate()->getYear();
        $month = \str_pad((string) $asset->getDate()->getMonth(), 2, '0', STR_PAD_LEFT);
        $fileName = $asset->getFileName();

        $targetPath = \sprintf(
            '%s/%s/%d/%s/%s',
            $this->baseDirectory,
            $category,
            $year,
            $month,
            $fileName
        );

        return new FilePath($targetPath);
    }
}
