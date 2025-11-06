<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use SortingPhotosByDate\Domain\Event\FileOrganized;
use SortingPhotosByDate\Ports\LoggerPort;

/**
 * Event handler for FileOrganized event.
 * Logs successful file organization.
 */
final readonly class FileOrganizedHandler
{
    public function __construct(
        private LoggerPort $logger,
    ) {
    }

    public function __invoke(FileOrganized $event): void
    {
        $this->logger->info('File organized successfully', [
            'source_path' => $event->getSourcePath()->getPath(),
            'target_path' => $event->getTargetPath()->getPath(),
            'file_type' => $event->getAsset()->getFileType()->value,
            'hash' => $event->getAsset()->getHash()->getHash(),
        ]);
    }
}
