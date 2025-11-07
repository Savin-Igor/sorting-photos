<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\RateLimiting;

/**
 * Token bucket rate limiter implementation.
 */
final class TokenBucketRateLimiter implements RateLimiter
{
    /** @var array<int, float> */
    private array $requests = [];
    private int $currentCount = 0;

    public function __construct(
        private readonly int $requestsPerWindow,
        private readonly int $windowSeconds,
        private readonly ?int $burst = null,
    ) {
        if ($this->requestsPerWindow <= 0) {
            throw new \InvalidArgumentException('Requests per window must be greater than 0');
        }
        if ($this->windowSeconds <= 0) {
            throw new \InvalidArgumentException('Window seconds must be greater than 0');
        }
    }

    public function waitIfNeeded(): void
    {
        $this->cleanOldRequests();
        $maxRequests = $this->burst ?? $this->requestsPerWindow;

        // Need to wait until oldest request expires
        if ($this->currentCount >= $maxRequests && [] !== $this->requests) {
            $oldestRequest = min($this->requests);
            $waitTime = $oldestRequest + $this->windowSeconds - microtime(true);
            if ($waitTime > 0) {
                usleep((int) ($waitTime * 1000000));
                $this->cleanOldRequests();
            }
        }

        $this->recordRequest();
    }

    public function recordRequest(): void
    {
        $this->cleanOldRequests();
        $this->requests[] = microtime(true);
        ++$this->currentCount;
    }

    public function getCurrentCount(): int
    {
        $this->cleanOldRequests();

        return $this->currentCount;
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->currentCount = 0;
    }

    private function cleanOldRequests(): void
    {
        $now = microtime(true);
        $cutoff = $now - $this->windowSeconds;

        $this->requests = array_filter(
            $this->requests,
            static fn (float $timestamp): bool => $timestamp > $cutoff
        );
        $this->currentCount = count($this->requests);
    }
}
