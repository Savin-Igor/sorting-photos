<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;

/**
 * Interface for creating UploadJob from file metadata.
 */
interface JobCreatorInterface
{
    /**
     * Create UploadJob from file metadata.
     *
     * @param FilePath           $filePath     File path
     * @param FileHash           $hash         File hash
     * @param MediaMeta          $metadata     Extracted metadata
     * @param string             $mimeType     MIME type (may be normalized)
     * @param bool               $isVideo      Whether file is a video
     * @param \DateTimeImmutable $creationTime Creation time
     *
     * @return UploadJob Created upload job
     */
    public function createJob(
        FilePath $filePath,
        FileHash $hash,
        MediaMeta $metadata,
        string $mimeType,
        bool $isVideo,
        \DateTimeImmutable $creationTime,
    ): UploadJob;
}
