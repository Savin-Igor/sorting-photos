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

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PENDING => \in_array($target, [self::UPLOADING, self::FAILED], true),
            self::UPLOADING => \in_array($target, [self::UPLOADED, self::PAUSED, self::FAILED], true),
            self::UPLOADED => \in_array($target, [self::IN_BATCH, self::FAILED], true),
            self::IN_BATCH => \in_array($target, [self::COMPLETED, self::FAILED], true),
            self::PAUSED => \in_array($target, [self::UPLOADING, self::FAILED], true),
            self::COMPLETED => false, // Финальное состояние
            self::FAILED => self::PENDING === $target, // Можно повторить
        };
    }

    public function getNextValidStates(): array
    {
        return match ($this) {
            self::PENDING => [self::UPLOADING, self::FAILED],
            self::UPLOADING => [self::UPLOADED, self::PAUSED, self::FAILED],
            self::UPLOADED => [self::IN_BATCH, self::FAILED],
            self::IN_BATCH => [self::COMPLETED, self::FAILED],
            self::PAUSED => [self::UPLOADING, self::FAILED],
            self::COMPLETED => [],
            self::FAILED => [self::PENDING],
        };
    }
}
