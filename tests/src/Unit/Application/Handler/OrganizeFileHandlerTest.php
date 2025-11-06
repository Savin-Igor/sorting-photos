<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Application\Handler;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Application\Command\OrganizeFileCommand;
use SortingPhotosByDate\Application\Handler\OrganizeFileHandler;
use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\Policies\OrganizerPolicy;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Domain\ValueObjects\FileType;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OrganizeFileHandlerTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject $filesystem;
    private \PHPUnit\Framework\MockObject\MockObject $policy;
    private \PHPUnit\Framework\MockObject\MockObject $logger;
    private \PHPUnit\Framework\MockObject\MockObject $messageBus;
    private OrganizeFileHandler $handler;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(FilesystemPort::class);
        $this->policy = $this->createMock(OrganizerPolicy::class);
        $this->logger = $this->createMock(LoggerPort::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->handler = new OrganizeFileHandler(
            $this->filesystem,
            $this->policy,
            $this->logger,
            $this->messageBus
        );
    }

    public function testHandleSuccessfullyOrganizesFile(): void
    {
        // Create temporary file for hash verification
        $tempFile = sys_get_temp_dir().'/test_file_'.uniqid().'.jpg';
        file_put_contents($tempFile, 'test content');
        $sourcePath = new FilePath($tempFile);
        $destinationBasePath = new FilePath('/destination');
        $targetPath = new FilePath('/destination/2025/11/images/file.jpg');

        $hashString = hash('sha256', 'test content');
        $hash = new FileHash($hashString);
        $asset = new MediaAsset(
            $sourcePath,
            FileType::IMAGE,
            new MediaMeta('file.jpg', 'image/jpeg', 12345),
            MediaDate::fromTimestamp(1730419200), // 2025-11-01
            $hash
        );

        $command = new OrganizeFileCommand($asset, $destinationBasePath);

        $this->policy
            ->expects($this->once())
            ->method('organize')
            ->with($asset)
            ->willReturn($targetPath);

        // Create temporary target file for hash verification
        $targetTempFile = sys_get_temp_dir().'/target_file_'.uniqid().'.jpg';

        $existsCallCount = 0;
        $this->filesystem
            ->expects($this->exactly(3))
            ->method('exists')
            ->willReturnCallback(function (FilePath $path) use ($targetPath, $sourcePath, &$existsCallCount): bool {
                ++$existsCallCount;
                // First call: check target path (doesn't exist) - in resolveCollision
                if (1 === $existsCallCount && $path->getPath() === $targetPath->getPath()) {
                    return false;
                }

                // Second and third calls: check source and target paths after copy (both exist for hash verification)
                return $path->getPath() === $sourcePath->getPath() || $path->getPath() === $targetPath->getPath();
            });

        $this->filesystem
            ->expects($this->once())
            ->method('copyWithMetadata')
            ->with($sourcePath, $targetPath)
            ->willReturnCallback(function () use ($targetTempFile, $tempFile): true {
                // Simulate copy by creating target file
                copy($tempFile, $targetTempFile);

                return true;
            });

        $this->filesystem
            ->expects($this->exactly(2))
            ->method('calculateHash')
            ->willReturnCallback(function (FilePath $path) use ($sourcePath, $targetPath, $hashString): string {
                // Return hash for both source and target
                return $hashString;
            });

        $this->filesystem
            ->expects($this->once())
            ->method('delete')
            ->with($sourcePath)
            ->willReturn(true);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\SortingPhotosByDate\Domain\Event\FileOrganized::class))
            ->willReturnCallback(function ($message) {
                return Envelope::wrap($message);
            });

        $result = $this->handler->handle($command);

        $this->assertEquals($targetPath->getPath(), $result->getPath());

        // Cleanup
        @unlink($tempFile);
        @unlink($targetTempFile);
    }

    public function testHandleHandlesFileCollision(): void
    {
        // Create temporary file for hash verification
        $tempFile = sys_get_temp_dir().'/test_file_'.uniqid().'.jpg';
        file_put_contents($tempFile, 'test content');
        $sourcePath = new FilePath($tempFile);
        $destinationBasePath = new FilePath('/destination');
        $targetPath = new FilePath('/destination/2025/11/images/file.jpg');

        $hashString = hash('sha256', 'test content');
        $hash = new FileHash($hashString);
        $shortHash = $hash->getShortHash(8);
        $collisionPath = new FilePath("/destination/2025/11/images/file-{$shortHash}.jpg");

        $asset = new MediaAsset(
            $sourcePath,
            FileType::IMAGE,
            new MediaMeta('file.jpg', 'image/jpeg', 12345),
            MediaDate::fromTimestamp(1730419200),
            $hash
        );

        $command = new OrganizeFileCommand($asset, $destinationBasePath);

        $this->policy
            ->expects($this->once())
            ->method('organize')
            ->with($asset)
            ->willReturn($targetPath);

        // Create temporary target file for hash verification
        $collisionTempFile = sys_get_temp_dir().'/collision_'.uniqid().'.jpg';

        $callCount = 0;
        $copyDone = false;
        $this->filesystem
            ->method('exists')
            ->willReturnCallback(function (FilePath $path) use ($targetPath, $sourcePath, $collisionPath, &$callCount, &$copyDone): bool {
                ++$callCount;
                // First call: check target path (exists) - in resolveCollision
                if (1 === $callCount && $path->getPath() === $targetPath->getPath()) {
                    return true;
                }
                // Second call: check collision path (doesn't exist) - in resolveCollision
                if (2 === $callCount && $path->getPath() === $collisionPath->getPath()) {
                    return false;
                }
                // After copyWithMetadata is called, both files should exist
                if ($copyDone) {
                    return $path->getPath() === $sourcePath->getPath() || $path->getPath() === $collisionPath->getPath();
                }

                return false;
            });

        $this->filesystem
            ->expects($this->once())
            ->method('copyWithMetadata')
            ->with($sourcePath, $this->callback(function (FilePath $path) use ($collisionPath, &$copyDone): bool {
                $copyDone = true;
                return $path->getPath() === $collisionPath->getPath();
            }))
            ->willReturn(true);

        $this->filesystem
            ->expects($this->exactly(2))
            ->method('calculateHash')
            ->willReturnCallback(function (FilePath $path) use ($sourcePath, $collisionPath, $hashString): string {
                // Return hash for both source and collision target
                if ($path->getPath() === $sourcePath->getPath() || $path->getPath() === $collisionPath->getPath()) {
                    return $hashString;
                }

                return $hashString;
            });

        $this->filesystem
            ->expects($this->once())
            ->method('delete')
            ->with($sourcePath)
            ->willReturn(true);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\SortingPhotosByDate\Domain\Event\FileOrganized::class))
            ->willReturnCallback(function ($message) {
                return Envelope::wrap($message);
            });

        $result = $this->handler->handle($command);

        $this->assertStringContainsString($shortHash, $result->getPath());

        // Cleanup
        @unlink($tempFile);
        if (file_exists($collisionTempFile)) {
            @unlink($collisionTempFile);
        }
    }

    public function testHandleThrowsExceptionWhenCopyFails(): void
    {
        // Create temporary file for hash verification
        $tempFile = sys_get_temp_dir().'/test_file_'.uniqid().'.jpg';
        file_put_contents($tempFile, 'test content');
        $sourcePath = new FilePath($tempFile);
        $destinationBasePath = new FilePath('/destination');
        $targetPath = new FilePath('/destination/2025/11/images/file.jpg');

        $hashString = hash('sha256', 'test content');
        $hash = new FileHash($hashString);
        $asset = new MediaAsset(
            $sourcePath,
            FileType::IMAGE,
            new MediaMeta('file.jpg', 'image/jpeg', 12345),
            MediaDate::fromTimestamp(1730419200),
            $hash
        );

        $command = new OrganizeFileCommand($asset, $destinationBasePath);

        $this->policy
            ->expects($this->once())
            ->method('organize')
            ->with($asset)
            ->willReturn($targetPath);

        $this->filesystem
            ->expects($this->once())
            ->method('exists')
            ->with($targetPath)
            ->willReturn(false);

        $this->filesystem
            ->expects($this->once())
            ->method('copyWithMetadata')
            ->with($sourcePath, $targetPath)
            ->willReturn(false);

        $this->filesystem
            ->expects($this->never())
            ->method('delete');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to copy file');

        try {
            $this->handler->handle($command);
        } finally {
            // Cleanup
            @unlink($tempFile);
        }
    }
}
