<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use Psr\Container\ContainerInterface;

/**
 * Factory for creating AsyncMessageBus instances.
 * This is needed because Symfony DI has issues with autowiring string parameters.
 */
final readonly class AsyncMessageBusFactory
{
    /**
     * @param array<string, string> $messageRouting
     */
    public static function create(
        ContainerInterface $container,
        string $transportDsn,
        array $messageRouting,
    ): AsyncMessageBus {
        return new AsyncMessageBus($container, $transportDsn, $messageRouting);
    }
}
