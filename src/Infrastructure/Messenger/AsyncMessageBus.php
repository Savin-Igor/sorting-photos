<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Messenger;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;

/**
 * Async MessageBus implementation that sends messages to Redis queue.
 * Messages are routed to async transport based on message type.
 *
 * @param array<string, string> $messageRouting Map of message class names to transport names
 */
final readonly class AsyncMessageBus implements MessageBusInterface
{
    private MessageBusInterface $bus;
    private TransportInterface $transport;

    /**
     * @param array<string, string> $messageRouting Map of message class names to transport names
     */
    public function __construct(
        private ContainerInterface $container,
        private string $transportDsn,
        private array $messageRouting = [],
    ) {
        $this->transport = $this->createTransport();
        $this->bus = $this->createMessageBus();
    }

    /**
     * Create Redis transport from DSN.
     */
    private function createTransport(): TransportInterface
    {
        $serializer = new PhpSerializer();

        // Try to get Redis transport factory
        // Symfony Messenger Bridge Redis provides RedisTransportFactory
        if (class_exists(\Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory::class)) {
            $factory = new \Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory();

            return $factory->createTransport($this->transportDsn, [], $serializer);
        }

        // Fallback: try to use container if factory is registered
        if ($this->container->has('messenger.transport_factory.redis')) {
            /** @var TransportFactoryInterface<TransportInterface> $factory */
            $factory = $this->container->get('messenger.transport_factory.redis');

            return $factory->createTransport($this->transportDsn, [], $serializer);
        }

        throw new \RuntimeException('Redis transport factory not found. Please ensure symfony/redis-messenger is installed and RedisTransportFactory is available.');
    }

    /**
     * Create MessageBus with SendMessageMiddleware.
     */
    private function createMessageBus(): MessageBusInterface
    {
        // Create sender that routes messages to async transport
        $sender = new class ($this->transport, $this->messageRouting) implements \Symfony\Component\Messenger\Transport\Sender\SenderInterface {
            /**
             * @param array<string, string> $routing Map of message class names to transport names
             */
            public function __construct(
                private readonly TransportInterface $transport,
                /** @var array<string, string> */
                private array $routing,
            ) {
            }

            public function send(Envelope $envelope): Envelope
            {
                // Check if message should be routed to async transport
                $messageClass = $envelope->getMessage()::class;
                if (isset($this->routing[$messageClass]) && 'async' === $this->routing[$messageClass]) {
                    $this->transport->send($envelope);
                }

                return $envelope;
            }
        };

        // Create SendersLocator that maps message types to senders
        // SendersLocator expects: ContainerInterface $sendersContainer, array<string, list<string>> $sendersMap
        $sendersContainer = new readonly class ($sender) implements ContainerInterface {
            public function __construct(
                private \Symfony\Component\Messenger\Transport\Sender\SenderInterface $sender,
            ) {
            }

            public function get(string $id): \Symfony\Component\Messenger\Transport\Sender\SenderInterface
            {
                return $this->sender;
            }

            public function has(string $id): bool
            {
                return 'async' === $id;
            }
        };

        // Convert array<string, string> to array<string, list<string>> format expected by SendersLocator
        /** @var array<string, list<string>> $sendersMap */
        $sendersMap = [];
        foreach ($this->messageRouting as $messageClass => $transport) {
            $sendersMap[$messageClass] = [$transport];
        }

        $sendersLocator = new \Symfony\Component\Messenger\Transport\Sender\SendersLocator(
            $sendersMap,
            $sendersContainer
        );

        $middleware = new SendMessageMiddleware($sendersLocator);

        return new \Symfony\Component\Messenger\MessageBus([$middleware]);
    }

    #[\Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message, $stamps);

        // Check routing - if message should go to async transport, send it there
        $messageClass = $message::class;
        if (isset($this->messageRouting[$messageClass]) && 'async' === $this->messageRouting[$messageClass]) {
            // Send to async transport (Redis queue)
            $this->transport->send($envelope);

            return $envelope;
        }

        // For messages not routed to async, process synchronously
        // This shouldn't happen in async mode, but provides fallback
        return $this->bus->dispatch($envelope);
    }

    /**
     * Get transport receiver for consuming messages.
     * Used by ConsumeMessagesCommand to consume messages from queue.
     */
    public function getReceiver(): ReceiverInterface
    {
        return $this->transport;
    }
}
