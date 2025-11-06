<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use SortingPhotosByDate\Domain\Event\FileError;
use SortingPhotosByDate\Ports\LoggerPort;

/**
 * Event handler for FileError event.
 * Logs file processing errors.
 */
final readonly class FileErrorHandler
{
    public function __construct(
        private LoggerPort $logger,
    ) {
    }

    public function __invoke(FileError $event): void
    {
        $context = [
            'file_path' => $event->getFilePath()->getPath(),
            'error_message' => $event->getErrorMessage(),
        ];

        if ($event->getException() instanceof \Throwable) {
            $context['exception'] = $event->getException()->getMessage();
            $context['exception_class'] = $event->getException()::class;
            $context['trace'] = $event->getException()->getTraceAsString();
        }

        $this->logger->error('File processing error occurred', $context);
    }
}
