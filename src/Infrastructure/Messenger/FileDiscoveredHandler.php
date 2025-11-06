<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use SortingPhotosByDate\Application\Command\IngestFileCommand;
use SortingPhotosByDate\Domain\Event\FileDiscovered;
use SortingPhotosByDate\Ports\LoggerPort;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Event handler for FileDiscovered event.
 * Dispatches IngestFileCommand to process the discovered file.
 */
final readonly class FileDiscoveredHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private LoggerPort $logger,
    ) {
    }

    public function __invoke(FileDiscovered $event): void
    {
        $this->logger->debug('File discovered, dispatching ingest command', [
            'file_path' => $event->getFilePath()->getPath(),
            'file_size' => $event->getFileSize(),
        ]);

        $command = new IngestFileCommand($event->getFilePath());
        $this->messageBus->dispatch($command);
    }
}
