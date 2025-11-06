<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Infrastructure\Filesystem;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Filesystem\MetadataPreservingCopier;
use SortingPhotosByDate\Ports\FilesystemPort;

final class MetadataPreservingCopierTest extends TestCase
{
    private MetadataPreservingCopier $copier;
    private FilesystemPort $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(FilesystemPort::class);
        $this->copier = new MetadataPreservingCopier($this->filesystem);
    }

    public function testCopyPreservesPermissions(): void
    {
        $sourcePath = new FilePath('/source/file.txt');
        $destinationPath = new FilePath('/destination/file.txt');
        $destinationDir = new FilePath('/destination');

        $this->filesystem
            ->expects($this->once())
            ->method('exists')
            ->with($sourcePath)
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('ensureDirectory')
            ->with($destinationDir);

        $this->filesystem
            ->expects($this->once())
            ->method('getPermissions')
            ->with($sourcePath)
            ->willReturn(0644);

        $this->filesystem
            ->expects($this->once())
            ->method('getModificationTime')
            ->with($sourcePath)
            ->willReturn(1234567890);

        $this->filesystem
            ->expects($this->once())
            ->method('getAccessTime')
            ->with($sourcePath)
            ->willReturn(1234567891);

        $this->filesystem
            ->expects($this->once())
            ->method('read')
            ->with($sourcePath)
            ->willReturn('test content');

        $this->filesystem
            ->expects($this->once())
            ->method('write')
            ->with($destinationPath, 'test content');

        $this->filesystem
            ->expects($this->exactly(2))
            ->method('calculateHash')
            ->willReturn('abc123hash');

        $this->filesystem
            ->expects($this->once())
            ->method('setPermissions')
            ->with($destinationPath, 0644)
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('setTimestamps')
            ->with($destinationPath, 1234567890, 1234567891)
            ->willReturn(true);

        $result = $this->copier->copy($sourcePath, $destinationPath);

        $this->assertTrue($result);
    }

    public function testCopyThrowsExceptionWhenSourceDoesNotExist(): void
    {
        $sourcePath = new FilePath('/nonexistent/file.txt');
        $destinationPath = new FilePath('/destination/file.txt');

        $this->filesystem
            ->expects($this->once())
            ->method('exists')
            ->with($sourcePath)
            ->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Source file does not exist');

        $this->copier->copy($sourcePath, $destinationPath);
    }

    public function testCopyVerifiesFileIntegrity(): void
    {
        $sourcePath = new FilePath('/source/file.txt');
        $destinationPath = new FilePath('/destination/file.txt');
        $destinationDir = new FilePath('/destination');

        $this->filesystem
            ->expects($this->once())
            ->method('exists')
            ->with($sourcePath)
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('ensureDirectory')
            ->with($destinationDir);

        $this->filesystem
            ->expects($this->once())
            ->method('getPermissions')
            ->with($sourcePath)
            ->willReturn(0644);

        $this->filesystem
            ->expects($this->once())
            ->method('getModificationTime')
            ->with($sourcePath)
            ->willReturn(1234567890);

        $this->filesystem
            ->expects($this->once())
            ->method('getAccessTime')
            ->with($sourcePath)
            ->willReturn(1234567891);

        $this->filesystem
            ->expects($this->once())
            ->method('read')
            ->with($sourcePath)
            ->willReturn('test content');

        $this->filesystem
            ->expects($this->once())
            ->method('write')
            ->with($destinationPath, 'test content');

        // Different hashes to trigger integrity check failure
        $this->filesystem
            ->expects($this->exactly(2))
            ->method('calculateHash')
            ->willReturnOnConsecutiveCalls('source_hash', 'different_hash');

        $this->filesystem
            ->expects($this->once())
            ->method('delete')
            ->with($destinationPath)
            ->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File integrity check failed');

        $this->copier->copy($sourcePath, $destinationPath);
    }

    public function testCopyCreatesDestinationDirectory(): void
    {
        $sourcePath = new FilePath('/source/file.txt');
        $destinationPath = new FilePath('/destination/subdir/file.txt');
        $destinationDir = new FilePath('/destination/subdir');

        $this->filesystem
            ->expects($this->once())
            ->method('exists')
            ->with($sourcePath)
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('ensureDirectory')
            ->with($destinationDir);

        $this->filesystem
            ->expects($this->once())
            ->method('getPermissions')
            ->with($sourcePath)
            ->willReturn(0644);

        $this->filesystem
            ->expects($this->once())
            ->method('getModificationTime')
            ->with($sourcePath)
            ->willReturn(1234567890);

        $this->filesystem
            ->expects($this->once())
            ->method('getAccessTime')
            ->with($sourcePath)
            ->willReturn(1234567891);

        $this->filesystem
            ->expects($this->once())
            ->method('read')
            ->with($sourcePath)
            ->willReturn('test content');

        $this->filesystem
            ->expects($this->once())
            ->method('write')
            ->with($destinationPath, 'test content');

        $this->filesystem
            ->expects($this->exactly(2))
            ->method('calculateHash')
            ->willReturn('abc123hash');

        $this->filesystem
            ->expects($this->once())
            ->method('setPermissions')
            ->with($destinationPath, 0644)
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('setTimestamps')
            ->with($destinationPath, 1234567890, 1234567891)
            ->willReturn(true);

        $result = $this->copier->copy($sourcePath, $destinationPath);

        $this->assertTrue($result);
    }
}
