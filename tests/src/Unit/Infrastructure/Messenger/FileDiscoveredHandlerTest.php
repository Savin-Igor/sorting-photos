<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Infrastructure\Messenger;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Application\Command\IngestFileCommand;
use SortingPhotosByDate\Domain\Event\FileDiscovered;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Messenger\FileDiscoveredHandler;
use SortingPhotosByDate\Ports\LoggerPort;
use Symfony\Component\Messenger\MessageBusInterface;

final class FileDiscoveredHandlerTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject $messageBus;
    private \PHPUnit\Framework\MockObject\MockObject $logger;
    private FileDiscoveredHandler $handler;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerPort::class);

        $this->handler = new FileDiscoveredHandler(
            $this->messageBus,
            $this->logger
        );
    }

    public function testHandleDispatchesIngestFileCommand(): void
    {
        $filePath = new FilePath('/path/to/file.jpg');
        $fileSize = 12345;
        $event = new FileDiscovered($filePath, $fileSize);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(IngestFileCommand::class))
            ->willReturnCallback(fn ($command): \Symfony\Component\Messenger\Envelope => new \Symfony\Component\Messenger\Envelope($command));

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('File discovered'),
                $this->arrayHasKey('file_path')
            );

        $this->handler->__invoke($event);
    }
}
