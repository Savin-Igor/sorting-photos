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
final readonly class SynchronousMessageBus implements MessageBusInterface
{
    private MessageBusInterface $bus;

    public function __construct(private ContainerInterface $container)
    {
        // Map message types to their handlers (lazy loaded)
        $handlersMap = [
            IngestFileCommand::class => [
                function (IngestFileCommand $command) {
                    /** @var \SortingPhotosByDate\Application\Handler\IngestFileHandler $handler */
                    $handler = $this->container->get(\SortingPhotosByDate\Application\Handler\IngestFileHandler::class);

                    return $handler->handle($command);
                },
            ],
            OrganizeFileCommand::class => [
                function (OrganizeFileCommand $command) {
                    /** @var \SortingPhotosByDate\Application\Handler\OrganizeFileHandler $handler */
                    $handler = $this->container->get(\SortingPhotosByDate\Application\Handler\OrganizeFileHandler::class);

                    return $handler->handle($command);
                },
            ],
            FileDiscovered::class => [
                function (FileDiscovered $event): void {
                    /** @var FileDiscoveredHandler $handler */
                    $handler = $this->container->get(FileDiscoveredHandler::class);
                    $handler->__invoke($event);
                },
            ],
            FileProcessed::class => [
                function (FileProcessed $event): void {
                    /** @var FileProcessedHandler $handler */
                    $handler = $this->container->get(FileProcessedHandler::class);
                    $handler->__invoke($event);
                },
            ],
            FileOrganized::class => [
                function (FileOrganized $event): void {
                    /** @var FileOrganizedHandler $handler */
                    $handler = $this->container->get(FileOrganizedHandler::class);
                    $handler->__invoke($event);
                },
            ],
            FileError::class => [
                function (FileError $event): void {
                    /** @var FileErrorHandler $handler */
                    $handler = $this->container->get(FileErrorHandler::class);
                    $handler->__invoke($event);
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
