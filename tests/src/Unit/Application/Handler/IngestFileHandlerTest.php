<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Application\Handler;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Application\Command\IngestFileCommand;
use SortingPhotosByDate\Application\Handler\IngestFileHandler;
use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\MetadataExtractorPort;
use SortingPhotosByDate\Ports\MetadataRepositoryPort;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class IngestFileHandlerTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject $filesystem;
    private \PHPUnit\Framework\MockObject\MockObject $metadataExtractor;
    private \PHPUnit\Framework\MockObject\MockObject $repository;
    private \PHPUnit\Framework\MockObject\MockObject $logger;
    private \PHPUnit\Framework\MockObject\MockObject $messageBus;
    private IngestFileHandler $handler;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(FilesystemPort::class);
        $this->metadataExtractor = $this->createMock(MetadataExtractorPort::class);
        $this->repository = $this->createMock(MetadataRepositoryPort::class);
        $this->logger = $this->createMock(LoggerPort::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->handler = new IngestFileHandler(
            $this->filesystem,
            $this->metadataExtractor,
            $this->repository,
            $this->logger,
            $this->messageBus
        );
    }

    public function testHandleSuccessfullyIngestsFile(): void
    {
        // Create temporary file for hash calculation
        $tempFile = sys_get_temp_dir().'/test_file_'.uniqid().'.jpg';
        file_put_contents($tempFile, 'test content');
        $filePath = new FilePath($tempFile);
        $command = new IngestFileCommand($filePath);

        $mimeType = 'image/jpeg';
        $fileSize = strlen('test content');
        $mediaDate = MediaDate::fromTimestamp(1234567890);
        $mediaMeta = new MediaMeta(
            'file.jpg',
            $mimeType,
            $fileSize
        );

        $this->filesystem
            ->expects($this->once())
            ->method('exists')
            ->with($filePath)
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('getSize')
            ->with($filePath)
            ->willReturn($fileSize);

        $this->filesystem
            ->expects($this->once())
            ->method('calculateHash')
            ->with($filePath)
            ->willReturn(hash('sha256', 'test content'));

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extract')
            ->with($filePath->getPath())
            ->willReturn($mediaMeta);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('supports')
            ->with($mimeType)
            ->willReturn(true);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extractDate')
            ->with($filePath->getPath())
            ->willReturn($mediaDate);

        $hashString = hash('sha256', 'test content');
        new FileHash($hashString);

        // Mock repository: no duplicate found
        $this->repository
            ->expects($this->once())
            ->method('findByHash')
            ->with($this->isInstanceOf(FileHash::class))
            ->willReturn(null);

        $this->repository
            ->expects($this->once())
            ->method('isProcessed')
            ->with($filePath, $fileSize, $this->isInstanceOf(FileHash::class))
            ->willReturn(false);

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(MediaAsset::class))
            ->willReturn(true);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\SortingPhotosByDate\Domain\Event\FileProcessed::class))
            ->willReturnCallback(fn(object $message): \Symfony\Component\Messenger\Envelope => Envelope::wrap($message));

        $result = $this->handler->handle($command);

        $this->assertInstanceOf(MediaAsset::class, $result);
        $this->assertEquals($filePath->getPath(), $result->getSourcePath()->getPath());

        // Cleanup
        @unlink($tempFile);
    }

    public function testHandleSkipsAlreadyProcessedFile(): void
    {
        // Create temporary file for hash calculation
        $tempFile = sys_get_temp_dir().'/test_file_'.uniqid().'.jpg';
        file_put_contents($tempFile, 'test content');
        $filePath = new FilePath($tempFile);
        $command = new IngestFileCommand($filePath);

        $mimeType = 'image/jpeg';
        $fileSize = strlen('test content');
        $mediaMeta = new MediaMeta(
            'file.jpg',
            $mimeType,
            $fileSize
        );

        $this->filesystem
            ->expects($this->once())
            ->method('exists')
            ->with($filePath)
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('getSize')
            ->with($filePath)
            ->willReturn($fileSize);

        $hashString = hash('sha256', 'test content');

        $this->filesystem
            ->expects($this->once())
            ->method('calculateHash')
            ->with($filePath)
            ->willReturn($hashString);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extract')
            ->with($filePath->getPath())
            ->willReturn($mediaMeta);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('supports')
            ->with($mimeType)
            ->willReturn(true);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extractDate')
            ->with($filePath->getPath())
            ->willReturn(MediaDate::fromTimestamp(time()));

        $this->repository
            ->expects($this->once())
            ->method('findByHash')
            ->with($this->isInstanceOf(FileHash::class))
            ->willReturn(null);

        $this->repository
            ->expects($this->once())
            ->method('isProcessed')
            ->with($filePath, $fileSize, $this->isInstanceOf(FileHash::class))
            ->willReturn(true);

        $this->repository
            ->expects($this->never())
            ->method('save');

        // Message bus should dispatch FileSkipped event for already processed files
        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn($event): bool => $event instanceof \SortingPhotosByDate\Domain\Event\FileSkipped
                && 'already_processed' === $event->getReason()))
            ->willReturn(new Envelope(new \SortingPhotosByDate\Domain\Event\FileSkipped($filePath, 'already_processed')));

        $result = $this->handler->handle($command);

        $this->assertNull($result);

        // Cleanup
        @unlink($tempFile);
    }

    public function testHandleSkipsDuplicateFile(): void
    {
        // Create temporary file
        $tempFile = sys_get_temp_dir().'/test_file_'.uniqid().'.jpg';
        file_put_contents($tempFile, 'test content');
        $filePath = new FilePath($tempFile);
        $command = new IngestFileCommand($filePath);

        $mimeType = 'image/jpeg';
        $fileSize = strlen('test content');
        $mediaMeta = new MediaMeta(
            'file.jpg',
            $mimeType,
            $fileSize
        );

        $hashString = hash('sha256', 'test content');
        $fileHash = new FileHash($hashString);

        // Create existing asset with same hash but different path
        $existingPath = new FilePath('/existing/path/file.jpg');
        $existingAsset = new MediaAsset(
            $existingPath,
            \SortingPhotosByDate\Domain\ValueObjects\FileType::IMAGE,
            new MediaMeta('existing.jpg', $mimeType, $fileSize),
            MediaDate::fromTimestamp(time()),
            $fileHash
        );

        $this->filesystem
            ->expects($this->once())
            ->method('exists')
            ->with($filePath)
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('getSize')
            ->with($filePath)
            ->willReturn($fileSize);

        $this->filesystem
            ->expects($this->once())
            ->method('calculateHash')
            ->with($filePath)
            ->willReturn($hashString);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extract')
            ->with($filePath->getPath())
            ->willReturn($mediaMeta);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('supports')
            ->with($mimeType)
            ->willReturn(true);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extractDate')
            ->with($filePath->getPath())
            ->willReturn(MediaDate::fromTimestamp(time()));

        // Mock repository: duplicate found
        $this->repository
            ->expects($this->once())
            ->method('findByHash')
            ->with($this->isInstanceOf(FileHash::class))
            ->willReturn($existingAsset);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Duplicate file detected (same hash, different path), skipping ingestion', $this->callback(fn(array $context): bool => $context['file_path'] === $filePath->getPath()
                && $context['existing_file_path'] === $existingPath->getPath()
                && $context['hash'] === $fileHash->getHash()));

        // Repository should not check isProcessed or save for duplicates
        $this->repository
            ->expects($this->never())
            ->method('isProcessed');

        $this->repository
            ->expects($this->never())
            ->method('save');

        // Message bus should dispatch FileSkipped event for duplicates
        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn($event): bool => $event instanceof \SortingPhotosByDate\Domain\Event\FileSkipped
                && 'duplicate' === $event->getReason()))
            ->willReturn(new Envelope(new \SortingPhotosByDate\Domain\Event\FileSkipped($filePath, 'duplicate', $existingPath->getPath())));

        $result = $this->handler->handle($command);

        $this->assertNull($result);

        // Cleanup
        @unlink($tempFile);
    }

    public function testHandleThrowsExceptionWhenFileDoesNotExist(): void
    {
        $filePath = new FilePath('/path/to/nonexistent.jpg');
        $command = new IngestFileCommand($filePath);

        $this->filesystem
            ->expects($this->once())
            ->method('exists')
            ->with($filePath)
            ->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File does not exist');

        $this->handler->handle($command);
    }
}
