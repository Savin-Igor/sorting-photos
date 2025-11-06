<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports;

/**
 * Port for logging operations.
 */
interface LoggerPort
{
    /**
     * Log debug message.
     *
     * @param string $message Message
     * @param array<string, mixed> $context Context data
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Log info message.
     *
     * @param string $message Message
     * @param array<string, mixed> $context Context data
     */
    public function info(string $message, array $context = []): void;

    /**
     * Log warning message.
     *
     * @param string $message Message
     * @param array<string, mixed> $context Context data
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Log error message.
     *
     * @param string $message Message
     * @param array<string, mixed> $context Context data
     */
    public function error(string $message, array $context = []): void;
}

