<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use SortingPhotosByDate\Domain\Event\FileSkipped;
use SortingPhotosByDate\Infrastructure\Statistics\RedisStatisticsService;
use SortingPhotosByDate\Ports\LoggerPort;

/**
 * Event handler for FileSkipped event.
 * Tracks skipped files for statistics using Redis.
 */
final readonly class FileSkippedHandler
{
    public function __construct(
        private LoggerPort $logger,
        private ?RedisStatisticsService $statistics = null,
    ) {
    }

    public function __invoke(FileSkipped $event): void
    {
        if ($this->statistics instanceof RedisStatisticsService) {
            $this->statistics->incrementSkipped();

            if ('duplicate' === $event->getReason()) {
                $this->statistics->incrementDuplicates();
            } elseif ('already_processed' === $event->getReason()) {
                $this->statistics->incrementAlreadyProcessed();
            }
        }

        $this->logger->debug('File skipped', [
            'file_path' => $event->getFilePath()->getPath(),
            'reason' => $event->getReason(),
            'existing_file_path' => $event->getExistingFilePath(),
        ]);
    }
}
