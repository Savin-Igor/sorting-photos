<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain\Event;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\Event\DomainEvent;
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

final class DomainEventTest extends TestCase
{
    public function testFileDiscoveredEvent(): void
    {
        $filePath = new FilePath('/path/to/file.jpg');
        $fileSize = 12345;

        $event = new FileDiscovered($filePath, $fileSize);

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals($filePath, $event->getFilePath());
        $this->assertEquals($fileSize, $event->getFileSize());
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->occurredAt());
    }

    public function testFileProcessedEvent(): void
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

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals($asset, $event->getAsset());
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->occurredAt());
    }

    public function testFileOrganizedEvent(): void
    {
        $sourcePath = new FilePath('/source/file.jpg');
        $targetPath = new FilePath('/target/file.jpg');
        $asset = new MediaAsset(
            $sourcePath,
            FileType::IMAGE,
            new MediaMeta('file.jpg', 'image/jpeg', 12345),
            MediaDate::fromTimestamp(1234567890),
            new FileHash('a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3')
        );

        $event = new FileOrganized($asset, $sourcePath, $targetPath);

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals($asset, $event->getAsset());
        $this->assertEquals($sourcePath, $event->getSourcePath());
        $this->assertEquals($targetPath, $event->getTargetPath());
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->occurredAt());
    }

    public function testFileErrorEvent(): void
    {
        $filePath = new FilePath('/path/to/file.jpg');
        $errorMessage = 'File processing failed';
        $exception = new \RuntimeException('Test exception');

        $event = new FileError($filePath, $errorMessage, $exception);

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals($filePath, $event->getFilePath());
        $this->assertEquals($errorMessage, $event->getErrorMessage());
        $this->assertEquals($exception, $event->getException());
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->occurredAt());
    }

    public function testFileErrorEventWithoutException(): void
    {
        $filePath = new FilePath('/path/to/file.jpg');
        $errorMessage = 'File processing failed';

        $event = new FileError($filePath, $errorMessage);

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals($filePath, $event->getFilePath());
        $this->assertEquals($errorMessage, $event->getErrorMessage());
        $this->assertNull($event->getException());
    }
}

