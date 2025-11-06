<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use SortingPhotosByDate\Application\Command\IngestFileCommand;
use SortingPhotosByDate\Application\Command\OrganizeFileCommand;
use SortingPhotosByDate\Application\Handler\IngestFileHandler;
use SortingPhotosByDate\Application\Handler\OrganizeFileHandler;
use SortingPhotosByDate\Domain\Event\FileDiscovered;
use SortingPhotosByDate\Domain\Event\FileError;
use SortingPhotosByDate\Domain\Event\FileOrganized;
use SortingPhotosByDate\Domain\Event\FileProcessed;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Simple synchronous MessageBus implementation for index.php.
 * Processes messages immediately without queuing.
 * Routes commands and events to their handlers.
 */
final class SynchronousMessageBus implements MessageBusInterface
{
    private MessageBusInterface $bus;

    public function __construct(
        IngestFileHandler $ingestHandler,
        OrganizeFileHandler $organizeHandler,
        FileDiscoveredHandler $fileDiscoveredHandler,
        FileProcessedHandler $fileProcessedHandler,
        FileOrganizedHandler $fileOrganizedHandler,
        FileErrorHandler $fileErrorHandler,
    ) {
        // Map message types to their handlers
        $handlersMap = [
            IngestFileCommand::class => [
                function (IngestFileCommand $command) use ($ingestHandler) {
                    return $ingestHandler->handle($command);
                },
            ],
            OrganizeFileCommand::class => [
                function (OrganizeFileCommand $command) use ($organizeHandler) {
                    return $organizeHandler->handle($command);
                },
            ],
            FileDiscovered::class => [
                function (FileDiscovered $event) use ($fileDiscoveredHandler) {
                    return $fileDiscoveredHandler->__invoke($event);
                },
            ],
            FileProcessed::class => [
                function (FileProcessed $event) use ($fileProcessedHandler) {
                    return $fileProcessedHandler->__invoke($event);
                },
            ],
            FileOrganized::class => [
                function (FileOrganized $event) use ($fileOrganizedHandler) {
                    return $fileOrganizedHandler->__invoke($event);
                },
            ],
            FileError::class => [
                function (FileError $event) use ($fileErrorHandler) {
                    return $fileErrorHandler->__invoke($event);
                },
            ],
        ];

        $handlersLocator = new HandlersLocator($handlersMap);
        $middleware = new HandleMessageMiddleware($handlersLocator);

        $this->bus = new \Symfony\Component\Messenger\MessageBus([$middleware]);
    }

    #[\Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return $this->bus->dispatch($message, $stamps);
    }
}

