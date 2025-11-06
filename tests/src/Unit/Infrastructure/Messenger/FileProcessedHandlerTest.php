<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Infrastructure\Messenger;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Application\Command\OrganizeFileCommand;
use SortingPhotosByDate\Domain\Event\FileProcessed;
use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Domain\ValueObjects\FileType;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Infrastructure\Messenger\FileProcessedHandler;
use SortingPhotosByDate\Ports\LoggerPort;
use Symfony\Component\Messenger\MessageBusInterface;

final class FileProcessedHandlerTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject $messageBus;
    private \PHPUnit\Framework\MockObject\MockObject $logger;
    private string $destinationBasePath;
    private FileProcessedHandler $handler;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerPort::class);
        $this->destinationBasePath = '/destination';

        $this->handler = new FileProcessedHandler(
            $this->messageBus,
            $this->logger,
            $this->destinationBasePath
        );
    }

    public function testHandleDispatchesOrganizeFileCommand(): void
    {
        $filePath = new FilePath('/path/to/file.jpg');
        $asset = new MediaAsset(
            $filePath,
            FileType::IMAGE,
            new MediaMeta('file.jpg', 'image/jpeg', 12345),
            MediaDate::fromTimestamp(1234567890),
            new FileHash('a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3')
        );
        $event = new FileProcessed($asset);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(OrganizeFileCommand::class))
            ->willReturnCallback(fn ($command): \Symfony\Component\Messenger\Envelope => new \Symfony\Component\Messenger\Envelope($command));

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('File processed'),
                $this->arrayHasKey('file_path')
            );

        $this->handler->__invoke($event);
    }
}
