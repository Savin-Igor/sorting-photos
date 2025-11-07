<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage;

/**
 * Rate limit configuration value object.
 */
final readonly class RateLimitConfiguration
{
    public function __construct(
        public int $requests,
        public int $perSeconds,
        public ?int $burst = null,
    ) {
        if ($this->requests <= 0) {
            throw new \InvalidArgumentException('Rate limit requests must be greater than 0');
        }
        if ($this->perSeconds <= 0) {
            throw new \InvalidArgumentException('Rate limit perSeconds must be greater than 0');
        }
    }
}
