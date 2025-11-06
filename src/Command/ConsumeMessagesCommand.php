<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use Psr\Container\ContainerInterface;
use SortingPhotosByDate\Infrastructure\Messenger\AsyncMessageBus;
use SortingPhotosByDate\Ports\LoggerPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Worker;

#[AsCommand(
    name: 'messenger:consume',
    description: 'Consume messages from async queue'
)]
final class ConsumeMessagesCommand extends Command
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerPort $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit the number of messages to consume')
            ->addOption('time-limit', null, InputOption::VALUE_OPTIONAL, 'Time limit in seconds')
            ->addOption('memory-limit', null, InputOption::VALUE_OPTIONAL, 'Memory limit in MB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check if async mode is enabled
        $asyncMode = getenv('ASYNC_MODE') ?: ($_ENV['ASYNC_MODE'] ?? 'false');
        $asyncMode = filter_var($asyncMode, FILTER_VALIDATE_BOOLEAN);

        if (!$asyncMode) {
            $io->error('Async mode is not enabled. Set ASYNC_MODE=true to use this command.');

            return Command::FAILURE;
        }

        // Get MessageBus - it should be AsyncMessageBus in async mode
        if (!$this->container->has(MessageBusInterface::class)) {
            $io->error('MessageBusInterface is not available in container.');

            return Command::FAILURE;
        }

        /** @var MessageBusInterface $messageBus */
        $messageBus = $this->container->get(MessageBusInterface::class);

        // If MessageBus is AsyncMessageBus, we can get transport from it
        if (!$messageBus instanceof AsyncMessageBus) {
            $io->error('MessageBus is not AsyncMessageBus. Async mode may not be properly configured.');

            return Command::FAILURE;
        }

        // Get receiver from AsyncMessageBus
        $receiver = $messageBus->getReceiver();

        // Create a synchronous message bus for processing messages (not sending to queue)
        // This bus will process messages through handlers without routing to async transport
        $processingBus = $this->createProcessingMessageBus();

        // Set limits
        $limitOption = $input->getOption('limit');
        /** @var int|null $limit */
        $limit = null !== $limitOption && '' !== $limitOption && is_numeric($limitOption) ? (int) $limitOption : null;
        $timeLimitOption = $input->getOption('time-limit');
        /** @var int|null $timeLimit */
        $timeLimit = null !== $timeLimitOption && '' !== $timeLimitOption && is_numeric($timeLimitOption) ? (int) $timeLimitOption : null;
        $memoryLimitOption = $input->getOption('memory-limit');
        /** @var int|null $memoryLimit */
        $memoryLimit = null !== $memoryLimitOption && '' !== $memoryLimitOption && is_numeric($memoryLimitOption) ? (int) $memoryLimitOption : null;

        $io->info('Starting message consumer...');
        $this->logger->info('Message consumer starting', [
            'worker_id' => getmypid(),
            'limit' => $limit,
            'time_limit' => $timeLimit,
            'memory_limit' => $memoryLimit,
        ]);

        // Create worker - it will get messages from receiver and dispatch them to processingBus
        $worker = new Worker(
            ['async' => $receiver],
            $processingBus,
            new \Symfony\Component\EventDispatcher\EventDispatcher()
        );

        $this->logger->info('Worker limits set', [
            'limit' => $limit,
            'time_limit' => $timeLimit,
            'memory_limit' => $memoryLimit,
        ]);

        try {
            $this->logger->info('Worker starting message processing loop');
            $worker->run([
                'limit' => $limit,
                'time-limit' => $timeLimit,
                'memory-limit' => $memoryLimit,
            ]);

            $io->success('Message consumer stopped');
            $this->logger->info('Message consumer stopped normally', [
                'worker_id' => getmypid(),
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Error consuming messages: '.$e->getMessage());
            $this->logger->error('Error consuming messages', [
                'error' => $e->getMessage(),
                'exception' => $e,
                'worker_id' => getmypid(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Create a MessageBus that processes messages synchronously through handlers.
     * This bus is used by Worker to process messages from queue without sending them back to queue.
     */
    private function createProcessingMessageBus(): MessageBusInterface
    {
        $this->logger->debug('Creating processing message bus');

        // Get all message handlers from container
        $handlersMap = [];

        // Get handlers for commands
        if ($this->container->has(\SortingPhotosByDate\Application\Handler\IngestFileHandler::class)) {
            $handlersMap[\SortingPhotosByDate\Application\Command\IngestFileCommand::class] = [
                function (\SortingPhotosByDate\Application\Command\IngestFileCommand $command) {
                    $this->logger->debug('Processing IngestFileCommand', [
                        'file_path' => $command->getFilePath()->getPath(),
                    ]);
                    /** @var \SortingPhotosByDate\Application\Handler\IngestFileHandler $handler */
                    $handler = $this->container->get(\SortingPhotosByDate\Application\Handler\IngestFileHandler::class);

                    return $handler->handle($command);
                },
            ];
        }

        if ($this->container->has(\SortingPhotosByDate\Application\Handler\OrganizeFileHandler::class)) {
            $handlersMap[\SortingPhotosByDate\Application\Command\OrganizeFileCommand::class] = [
                function (\SortingPhotosByDate\Application\Command\OrganizeFileCommand $command) {
                    $this->logger->debug('Processing OrganizeFileCommand', [
                        'file_path' => $command->getAsset()->getSourcePath()->getPath(),
                    ]);
                    /** @var \SortingPhotosByDate\Application\Handler\OrganizeFileHandler $handler */
                    $handler = $this->container->get(\SortingPhotosByDate\Application\Handler\OrganizeFileHandler::class);
                    try {
                        $result = $handler->handle($command);
                        $this->logger->debug('OrganizeFileCommand processed successfully', [
                            'result_path' => $result->getPath(),
                        ]);

                        return $result;
                    } catch (\Throwable $e) {
                        $this->logger->error('Error processing OrganizeFileCommand', [
                            'error' => $e->getMessage(),
                            'exception' => $e,
                            'file_path' => $command->getAsset()->getSourcePath()->getPath(),
                        ]);
                        throw $e;
                    }
                },
            ];
        }

        // Get handlers for events
        if ($this->container->has(\SortingPhotosByDate\Infrastructure\Messenger\FileDiscoveredHandler::class)) {
            $handlersMap[\SortingPhotosByDate\Domain\Event\FileDiscovered::class] = [
                function (\SortingPhotosByDate\Domain\Event\FileDiscovered $event): void {
                    $this->logger->debug('Processing FileDiscovered event', [
                        'file_path' => $event->getFilePath()->getPath(),
                    ]);
                    /** @var \SortingPhotosByDate\Infrastructure\Messenger\FileDiscoveredHandler $handler */
                    $handler = $this->container->get(\SortingPhotosByDate\Infrastructure\Messenger\FileDiscoveredHandler::class);
                    $handler->__invoke($event);
                },
            ];
        }

        if ($this->container->has(\SortingPhotosByDate\Infrastructure\Messenger\FileProcessedHandler::class)) {
            $handlersMap[\SortingPhotosByDate\Domain\Event\FileProcessed::class] = [
                function (\SortingPhotosByDate\Domain\Event\FileProcessed $event): void {
                    $this->logger->debug('Processing FileProcessed event', [
                        'file_path' => $event->getAsset()->getSourcePath()->getPath(),
                    ]);
                    /** @var \SortingPhotosByDate\Infrastructure\Messenger\FileProcessedHandler $handler */
                    $handler = $this->container->get(\SortingPhotosByDate\Infrastructure\Messenger\FileProcessedHandler::class);
                    $handler->__invoke($event);
                },
            ];
        }

        if ($this->container->has(\SortingPhotosByDate\Infrastructure\Messenger\FileOrganizedHandler::class)) {
            $handlersMap[\SortingPhotosByDate\Domain\Event\FileOrganized::class] = [
                function (\SortingPhotosByDate\Domain\Event\FileOrganized $event): void {
                    $this->logger->debug('Processing FileOrganized event', [
                        'source_path' => $event->getSourcePath()->getPath(),
                        'target_path' => $event->getTargetPath()->getPath(),
                    ]);
                    /** @var \SortingPhotosByDate\Infrastructure\Messenger\FileOrganizedHandler $handler */
                    $handler = $this->container->get(\SortingPhotosByDate\Infrastructure\Messenger\FileOrganizedHandler::class);
                    $handler->__invoke($event);
                },
            ];
        }

        if ($this->container->has(\SortingPhotosByDate\Infrastructure\Messenger\FileErrorHandler::class)) {
            $handlersMap[\SortingPhotosByDate\Domain\Event\FileError::class] = [
                function (\SortingPhotosByDate\Domain\Event\FileError $event): void {
                    $this->logger->debug('Processing FileError event', [
                        'file_path' => $event->getFilePath()->getPath(),
                        'error' => $event->getErrorMessage(),
                    ]);
                    /** @var \SortingPhotosByDate\Infrastructure\Messenger\FileErrorHandler $handler */
                    $handler = $this->container->get(\SortingPhotosByDate\Infrastructure\Messenger\FileErrorHandler::class);
                    $handler->__invoke($event);
                },
            ];
        }

        if ($this->container->has(\SortingPhotosByDate\Infrastructure\Messenger\FileSkippedHandler::class)) {
            $handlersMap[\SortingPhotosByDate\Domain\Event\FileSkipped::class] = [
                function (\SortingPhotosByDate\Domain\Event\FileSkipped $event): void {
                    $this->logger->debug('Processing FileSkipped event', [
                        'file_path' => $event->getFilePath()->getPath(),
                        'reason' => $event->getReason(),
                    ]);
                    /** @var \SortingPhotosByDate\Infrastructure\Messenger\FileSkippedHandler $handler */
                    $handler = $this->container->get(\SortingPhotosByDate\Infrastructure\Messenger\FileSkippedHandler::class);
                    $handler->__invoke($event);
                },
            ];
        }

        $this->logger->debug('Processing message bus created', [
            'handlers_count' => count($handlersMap),
        ]);

        $handlersLocator = new HandlersLocator($handlersMap);
        $middleware = new HandleMessageMiddleware($handlersLocator);

        return new \Symfony\Component\Messenger\MessageBus([$middleware]);
    }
}
