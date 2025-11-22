<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Logger;

use Monolog\Handler\HandlerInterface;
use Monolog\Handler\ProcessableHandlerTrait;
use Monolog\LogRecord;

/**
 * Custom Monolog handler that filters log records by allowed levels.
 * Only logs with levels in the allowed list will be passed to the wrapped handler.
 */
final class LevelFilterHandler implements HandlerInterface
{
    use ProcessableHandlerTrait;

    /**
     * @param HandlerInterface $handler       Wrapped handler to forward allowed records to
     * @param array<int>       $allowedLevels Array of Monolog level constants (e.g., [Logger::ERROR, Logger::WARNING])
     */
    public function __construct(
        private readonly HandlerInterface $handler,
        private readonly array $allowedLevels,
    ) {
    }

    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        // Process processors if any
        $record = $this->processRecord($record);

        // Forward to wrapped handler
        return $this->handler->handle($record);
    }

    public function isHandling(LogRecord $record): bool
    {
        return in_array($record->level->value, $this->allowedLevels, true);
    }

    public function handleBatch(array $records): void
    {
        $filtered = [];
        foreach ($records as $record) {
            if ($this->isHandling($record)) {
                $filtered[] = $record;
            }
        }

        if ([] !== $filtered) {
            $this->handler->handleBatch($filtered);
        }
    }

    public function close(): void
    {
        $this->handler->close();
    }
}
