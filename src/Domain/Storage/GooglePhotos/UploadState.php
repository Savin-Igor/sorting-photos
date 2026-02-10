<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Storage\GooglePhotos;

enum UploadState: string
{
    case PENDING = 'pending';
    case UPLOADING = 'uploading';
    case UPLOADED = 'uploaded';
    case IN_BATCH = 'in_batch';
    case COMPLETED = 'completed';
    case PAUSED = 'paused';
    case FAILED = 'failed';
    case ARCHIVED = 'archived'; // Files stored in DB but excluded from processing
    case NOT_FOUND = 'not_found'; // Files that don't exist (moved or external drive disconnected)

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PENDING => \in_array($target, [self::UPLOADING, self::FAILED, self::ARCHIVED, self::NOT_FOUND], true),
            self::UPLOADING => \in_array($target, [self::UPLOADED, self::PAUSED, self::FAILED, self::ARCHIVED, self::NOT_FOUND], true),
            self::UPLOADED => \in_array($target, [self::IN_BATCH, self::FAILED, self::ARCHIVED], true),
            self::IN_BATCH => \in_array($target, [self::COMPLETED, self::FAILED, self::ARCHIVED], true),
            self::PAUSED => \in_array($target, [self::UPLOADING, self::FAILED, self::ARCHIVED, self::NOT_FOUND], true),
            self::COMPLETED => false, // Final state - cannot archive completed files
            self::FAILED => \in_array($target, [self::PENDING, self::ARCHIVED, self::NOT_FOUND], true), // Can retry, archive, or mark as not found
            self::ARCHIVED => self::PENDING === $target, // Can unarchive to resume processing
            self::NOT_FOUND => false, // Final state - file doesn't exist, cannot process
        };
    }

    public function getNextValidStates(): array
    {
        return match ($this) {
            self::PENDING => [self::UPLOADING, self::FAILED, self::ARCHIVED, self::NOT_FOUND],
            self::UPLOADING => [self::UPLOADED, self::PAUSED, self::FAILED, self::ARCHIVED, self::NOT_FOUND],
            self::UPLOADED => [self::IN_BATCH, self::FAILED, self::ARCHIVED],
            self::IN_BATCH => [self::COMPLETED, self::FAILED, self::ARCHIVED],
            self::PAUSED => [self::UPLOADING, self::FAILED, self::ARCHIVED, self::NOT_FOUND],
            self::COMPLETED => [],
            self::FAILED => [self::PENDING, self::ARCHIVED, self::NOT_FOUND],
            self::ARCHIVED => [self::PENDING], // Can unarchive to resume
            self::NOT_FOUND => [], // Final state - file doesn't exist
        };
    }
}
