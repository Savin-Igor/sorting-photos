<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use SortingPhotosByDate\Exceptions\ValidationException;
use SortingPhotosByDate\Ports\RetryPolicyInterface;

/**
 * Retry policy implementation using configuration.
 * Can be used with Symfony Messenger retry strategy.
 */
final readonly class RetryPolicy implements RetryPolicyInterface
{
    /**
     * @param array<string> $nonRetryableExceptions List of exception class names that should not be retried
     */
    public function __construct(
        private int $maxRetries = 3,
        private int $delay = 1000,
        private float $multiplier = 2.0,
        private int $maxDelay = 60000,
        private array $nonRetryableExceptions = [
            \InvalidArgumentException::class,
            \TypeError::class,
        ],
    ) {
        if ($this->maxRetries < 0) {
            throw ValidationException::negativeValue('Max retries');
        }

        if ($this->delay < 0) {
            throw ValidationException::negativeValue('Delay');
        }

        if ($this->multiplier < 1.0) {
            throw ValidationException::positiveValue('Multiplier');
        }

        if ($this->maxDelay < 0) {
            throw ValidationException::negativeValue('Max delay');
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
        $exceptionClass = $exception::class;

        return array_all($this->nonRetryableExceptions, fn ($nonRetryableClass): bool => !$exception instanceof $nonRetryableClass && $exceptionClass !== $nonRetryableClass);
    }
}
