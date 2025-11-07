<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\RateLimiting;

/**
 * Rate limiter interface.
 */
interface RateLimiter
{
    /**
     * Wait if necessary to respect rate limit.
     */
    public function waitIfNeeded(): void;

    /**
     * Record that a request was made.
     */
    public function recordRequest(): void;

    /**
     * Get number of requests made in current window.
     */
    public function getCurrentCount(): int;

    /**
     * Reset rate limiter state.
     */
    public function reset(): void;
}
