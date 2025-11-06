<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use SortingPhotosByDate\Ports\RetryPolicyInterface;

/**
 * Retry policy implementation using configuration.
 * Can be used with Symfony Messenger retry strategy.
 */
final class RetryPolicy implements RetryPolicyInterface
{
    /**
     * @param array<string> $nonRetryableExceptions List of exception class names that should not be retried
     */
    public function __construct(
        private readonly int $maxRetries = 3,
        private readonly int $delay = 1000,
        private readonly float $multiplier = 2.0,
        private readonly int $maxDelay = 60000,
        private readonly array $nonRetryableExceptions = [
            \InvalidArgumentException::class,
            \TypeError::class,
        ],
    ) {
        if ($this->maxRetries < 0) {
            throw new \InvalidArgumentException('Max retries must be >= 0');
        }

        if ($this->delay < 0) {
            throw new \InvalidArgumentException('Delay must be >= 0');
        }

        if ($this->multiplier < 1.0) {
            throw new \InvalidArgumentException('Multiplier must be >= 1.0');
        }

        if ($this->maxDelay < 0) {
            throw new \InvalidArgumentException('Max delay must be >= 0');
        }
    }

    #[\Override]
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    #[\Override]
    public function getDelay(): int
    {
        return $this->delay;
    }

    #[\Override]
    public function getMultiplier(): float
    {
        return $this->multiplier;
    }

    #[\Override]
    public function getMaxDelay(): int
    {
        return $this->maxDelay;
    }

    #[\Override]
    public function shouldRetry(\Throwable $exception): bool
    {
        $exceptionClass = get_class($exception);

        foreach ($this->nonRetryableExceptions as $nonRetryableClass) {
            if ($exception instanceof $nonRetryableClass || $exceptionClass === $nonRetryableClass) {
                return false;
            }
        }

        return true;
    }
}
