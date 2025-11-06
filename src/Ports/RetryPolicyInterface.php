<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports;

/**
 * Port for retry policy configuration.
 * Abstracts retry strategy configuration for message processing.
 */
interface RetryPolicyInterface
{
    /**
     * Get maximum number of retries.
     *
     * @return int Maximum retries (0 = no retries)
     */
    public function getMaxRetries(): int;

    /**
     * Get initial delay in milliseconds before first retry.
     *
     * @return int Delay in milliseconds
     */
    public function getDelay(): int;

    /**
     * Get multiplier for exponential backoff.
     *
     * @return float Multiplier (e.g., 2.0 means delay doubles each retry)
     */
    public function getMultiplier(): float;

    /**
     * Get maximum delay in milliseconds.
     *
     * @return int Maximum delay in milliseconds (0 = no limit)
     */
    public function getMaxDelay(): int;

    /**
     * Check if retry should be attempted for given exception.
     *
     * @param \Throwable $exception Exception that occurred
     *
     * @return bool True if retry should be attempted
     */
    public function shouldRetry(\Throwable $exception): bool;
}
