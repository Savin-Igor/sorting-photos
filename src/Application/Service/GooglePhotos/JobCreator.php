<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;

/**
 * Service for creating UploadJob from file metadata.
 * Encapsulates job creation logic.
 */
final readonly class JobCreator implements JobCreatorInterface
{
    public function createJob(
        FilePath $filePath,
        FileHash $hash,
        MediaMeta $metadata,
        string $mimeType,
        bool $isVideo,
        \DateTimeImmutable $creationTime,
    ): UploadJob {
        return UploadJob::create(
            filePath: $filePath,
            fileSize: $metadata->getFileSize(),
            fileHash: $hash,
            mimeType: $mimeType,
            isVideo: $isVideo,
            creationTime: $creationTime,
        );
    }
}
