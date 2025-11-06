<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Infrastructure\Messenger;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Application\Command\IngestFileCommand;
use SortingPhotosByDate\Application\Command\OrganizeFileCommand;
use SortingPhotosByDate\Domain\Event\FileDiscovered;
use SortingPhotosByDate\Domain\Event\FileError;
use SortingPhotosByDate\Domain\Event\FileOrganized;
use SortingPhotosByDate\Domain\Event\FileProcessed;
use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Domain\ValueObjects\FileType;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Infrastructure\Messenger\FileDiscoveredHandler;
use SortingPhotosByDate\Infrastructure\Messenger\FileProcessedHandler;
use SortingPhotosByDate\Ports\LoggerPort;
use Symfony\Component\Messenger\MessageBusInterface;

final class FileDiscoveredHandlerTest extends TestCase
{
    private MessageBusInterface $messageBus;
    private LoggerPort $logger;
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
            ->willReturnCallback(function ($command) {
                return new \Symfony\Component\Messenger\Envelope($command);
            });

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

