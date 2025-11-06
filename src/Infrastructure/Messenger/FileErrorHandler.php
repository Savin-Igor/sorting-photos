<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use SortingPhotosByDate\Domain\Event\FileError;
use SortingPhotosByDate\Ports\LoggerPort;

/**
 * Event handler for FileError event.
 * Logs file processing errors.
 */
final class FileErrorHandler
{
    public function __construct(
        private readonly LoggerPort $logger,
    ) {
    }

    public function __invoke(FileError $event): void
    {
        $context = [
            'file_path' => $event->getFilePath()->getPath(),
            'error_message' => $event->getErrorMessage(),
        ];

        if (null !== $event->getException()) {
            $context['exception'] = $event->getException()->getMessage();
            $context['exception_class'] = get_class($event->getException());
            $context['trace'] = $event->getException()->getTraceAsString();
        }

        $this->logger->error('File processing error occurred', $context);
    }
}
