<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain;

use SortingPhotosByDate\Domain\ValueObjects\FileCategory;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Domain\ValueObjects\FileType;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;

final class MediaAsset
{
    public function __construct(
        private readonly FilePath $sourcePath,
        private readonly FileType $fileType,
        private readonly MediaMeta $metadata,
        private readonly MediaDate $date,
        private readonly FileHash $hash
    ) {
    }

    public function getSourcePath(): FilePath
    {
        return $this->sourcePath;
    }

    public function getFileType(): FileType
    {
        return $this->fileType;
    }

    public function getCategory(): FileCategory
    {
        return $this->fileType->toCategory();
    }

    public function getMetadata(): MediaMeta
    {
        return $this->metadata;
    }

    public function getDate(): MediaDate
    {
        return $this->date;
    }

    public function getHash(): FileHash
    {
        return $this->hash;
    }

    public function getFileName(): string
    {
        return $this->metadata->getFileName();
    }

    public function getMimeType(): string
    {
        return $this->metadata->getMimeType();
    }

    public function getFileSize(): int
    {
        return $this->metadata->getFileSize();
    }
}

