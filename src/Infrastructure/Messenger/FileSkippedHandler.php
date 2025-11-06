<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use SortingPhotosByDate\Domain\Event\FileSkipped;
use SortingPhotosByDate\Ports\LoggerPort;

/**
 * Event handler for FileSkipped event.
 * Tracks skipped files for statistics.
 */
final class FileSkippedHandler
{
    private static int $skippedCount = 0;
    private static int $duplicateCount = 0;
    private static int $alreadyProcessedCount = 0;

    public function __construct(
        private readonly LoggerPort $logger,
    ) {
    }

    public function __invoke(FileSkipped $event): void
    {
        ++self::$skippedCount;

        if ('duplicate' === $event->getReason()) {
            ++self::$duplicateCount;
        } elseif ('already_processed' === $event->getReason()) {
            ++self::$alreadyProcessedCount;
        }

        $this->logger->debug('File skipped', [
            'file_path' => $event->getFilePath()->getPath(),
            'reason' => $event->getReason(),
            'existing_file_path' => $event->getExistingFilePath(),
        ]);
    }

    /**
     * Get statistics about skipped files.
     *
     * @return array{total: int, duplicates: int, already_processed: int}
     */
    public static function getStats(): array
    {
        return [
            'total' => self::$skippedCount,
            'duplicates' => self::$duplicateCount,
            'already_processed' => self::$alreadyProcessedCount,
        ];
    }

    /**
     * Reset statistics (useful for testing).
     */
    public static function resetStats(): void
    {
        self::$skippedCount = 0;
        self::$duplicateCount = 0;
        self::$alreadyProcessedCount = 0;
    }
}
