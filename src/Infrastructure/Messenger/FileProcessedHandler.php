<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use SortingPhotosByDate\Application\Command\OrganizeFileCommand;
use SortingPhotosByDate\Domain\Event\FileProcessed;
use SortingPhotosByDate\Ports\LoggerPort;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Event handler for FileProcessed event.
 * Dispatches OrganizeFileCommand to organize the processed file.
 */
final readonly class FileProcessedHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private LoggerPort $logger,
        private string $destinationBasePath,
    ) {
    }

    public function __invoke(FileProcessed $event): void
    {
        $asset = $event->getAsset();

        $this->logger->debug('File processed, dispatching organize command', [
            'file_path' => $asset->getSourcePath()->getPath(),
            'file_type' => $asset->getFileType()->value,
            'hash' => $asset->getHash()->getHash(),
        ]);

        $destinationPath = new \SortingPhotosByDate\Domain\ValueObjects\FilePath($this->destinationBasePath);
        $command = new OrganizeFileCommand($asset, $destinationPath);
        $this->messageBus->dispatch($command);
    }
}
