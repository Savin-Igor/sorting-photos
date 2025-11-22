<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage;

use SortingPhotosByDate\Exceptions\ValidationException;

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
            throw ValidationException::positiveValue('Rate limit requests');
        }
        if ($this->perSeconds <= 0) {
            throw ValidationException::positiveValue('Rate limit perSeconds');
        }
    }
}
