<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Logger;

use SortingPhotosByDate\Ports\LoggerPort;
use Psr\Log\LoggerInterface;

final class MonologAdapter implements LoggerPort
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    #[\Override]
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    #[\Override]
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    #[\Override]
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }
}
