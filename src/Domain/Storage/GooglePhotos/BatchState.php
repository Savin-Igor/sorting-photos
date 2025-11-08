<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Storage\GooglePhotos;

enum BatchState: string
{
    case COLLECTING = 'collecting';
    case READY = 'ready';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case PAUSED = 'paused';
    case FAILED = 'failed';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::COLLECTING => \in_array($target, [self::READY, self::FAILED], true),
            self::READY => \in_array($target, [self::PROCESSING, self::FAILED], true),
            self::PROCESSING => \in_array($target, [self::COMPLETED, self::PAUSED, self::FAILED], true),
            self::PAUSED => \in_array($target, [self::PROCESSING, self::FAILED], true),
            self::COMPLETED => false, // Final state
            self::FAILED => self::READY === $target, // Can retry
        };
    }
}
