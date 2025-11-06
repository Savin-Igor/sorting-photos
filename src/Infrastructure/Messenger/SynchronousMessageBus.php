<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use SortingPhotosByDate\Application\Command\IngestFileCommand;
use SortingPhotosByDate\Application\Command\OrganizeFileCommand;
use SortingPhotosByDate\Domain\Event\FileDiscovered;
use SortingPhotosByDate\Domain\Event\FileError;
use SortingPhotosByDate\Domain\Event\FileOrganized;
use SortingPhotosByDate\Domain\Event\FileProcessed;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * Simple synchronous MessageBus implementation for index.php.
 * Processes messages immediately without queuing.
 * Routes commands and events to their handlers using lazy loading to avoid circular dependencies.
 */
final class SynchronousMessageBus implements MessageBusInterface
{
    private MessageBusInterface $bus;
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

        // Map message types to their handlers (lazy loaded)
        $handlersMap = [
            IngestFileCommand::class => [
                function (IngestFileCommand $command) {
                    $handler = $this->container->get(\SortingPhotosByDate\Application\Handler\IngestFileHandler::class);

                    return $handler->handle($command);
                },
            ],
            OrganizeFileCommand::class => [
                function (OrganizeFileCommand $command) {
                    $handler = $this->container->get(\SortingPhotosByDate\Application\Handler\OrganizeFileHandler::class);

                    return $handler->handle($command);
                },
            ],
            FileDiscovered::class => [
                function (FileDiscovered $event) {
                    $handler = $this->container->get(FileDiscoveredHandler::class);

                    return $handler->__invoke($event);
                },
            ],
            FileProcessed::class => [
                function (FileProcessed $event) {
                    $handler = $this->container->get(FileProcessedHandler::class);

                    return $handler->__invoke($event);
                },
            ],
            FileOrganized::class => [
                function (FileOrganized $event) {
                    $handler = $this->container->get(FileOrganizedHandler::class);

                    return $handler->__invoke($event);
                },
            ],
            FileError::class => [
                function (FileError $event) {
                    $handler = $this->container->get(FileErrorHandler::class);

                    return $handler->__invoke($event);
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
