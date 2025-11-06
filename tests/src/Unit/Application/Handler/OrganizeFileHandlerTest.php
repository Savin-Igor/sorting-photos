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

final class OrganizeFileHandlerTest extends TestCase
{
    private FilesystemPort $filesystem;
    private OrganizerPolicy $policy;
    private LoggerPort $logger;
    private OrganizeFileHandler $handler;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(FilesystemPort::class);
        $this->policy = $this->createMock(OrganizerPolicy::class);
        $this->logger = $this->createMock(LoggerPort::class);

        $this->handler = new OrganizeFileHandler(
            $this->filesystem,
            $this->policy,
            $this->logger
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

        $hash = FileHash::fromFile($tempFile);
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

        $this->filesystem
            ->expects($this->exactly(3))
            ->method('exists')
            ->willReturnCallback(function (FilePath $path) use ($targetPath, $sourcePath, $targetTempFile) {
                // First call: check target path (doesn't exist)
                if ($path->getPath() === $targetPath->getPath()) {
                    return false;
                }

                // Second and third calls: check source and target paths after copy (both exist for hash verification)
                return $path->getPath() === $sourcePath->getPath() || file_exists($targetTempFile);
            });

        $this->filesystem
            ->expects($this->once())
            ->method('copyWithMetadata')
            ->with($sourcePath, $targetPath)
            ->willReturnCallback(function () use ($targetTempFile, $tempFile) {
                // Simulate copy by creating target file
                copy($tempFile, $targetTempFile);

                return true;
            });

        $this->filesystem
            ->expects($this->once())
            ->method('delete')
            ->with($sourcePath)
            ->willReturn(true);

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

        $hash = FileHash::fromFile($tempFile);
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
        $this->filesystem
            ->method('exists')
            ->willReturnCallback(function (FilePath $path) use ($targetPath, $sourcePath, $shortHash, $collisionTempFile, $tempFile, &$callCount) {
                ++$callCount;
                // First call: check target path (exists) - in resolveCollision
                if (1 === $callCount && $path->getPath() === $targetPath->getPath()) {
                    return true;
                }
                // Second call: check collision path (doesn't exist) - in resolveCollision
                if (2 === $callCount && str_contains($path->getPath(), $shortHash)) {
                    return false;
                }
                // Third call: check source path after copy (exists) - before verifyHash
                if (3 === $callCount && $path->getPath() === $sourcePath->getPath()) {
                    return true;
                }
                // Fourth call: check collision path after copy (exists - create temp file) - before verifyHash
                if (4 === $callCount && str_contains($path->getPath(), $shortHash)) {
                    copy($tempFile, $collisionTempFile);

                    return true;
                }

                return false;
            });

        $this->filesystem
            ->expects($this->once())
            ->method('copyWithMetadata')
            ->with($sourcePath, $this->callback(function (FilePath $path) use ($shortHash, $tempFile, $collisionTempFile) {
                if (str_contains($path->getPath(), $shortHash)) {
                    // Create file at collision path location for hash verification
                    copy($tempFile, $collisionTempFile);

                    return true;
                }

                return false;
            }))
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('delete')
            ->with($sourcePath)
            ->willReturn(true);

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

        $hash = FileHash::fromFile($tempFile);
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
