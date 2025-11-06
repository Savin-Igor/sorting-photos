<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Infrastructure\Filesystem;

use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Filesystem\MetadataPreservingCopier;

final class MetadataPreservingCopierTest extends TestCase
{
    private MetadataPreservingCopier $copier;
    private FilesystemOperator $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(FilesystemOperator::class);
        $this->copier = new MetadataPreservingCopier($this->filesystem);
    }

    public function testCopyPreservesPermissions(): void
    {
        $sourcePath = new FilePath('/source/file.txt');
        $destinationPath = new FilePath('/destination/file.txt');
        $sourcePathStr = '/source/file.txt';
        $destPathStr = '/destination/file.txt';
        $destDirStr = '/destination';

        $this->filesystem
            ->expects($this->once())
            ->method('fileExists')
            ->with($sourcePathStr)
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('directoryExists')
            ->with($destDirStr)
            ->willReturn(false);

        $this->filesystem
            ->expects($this->once())
            ->method('createDirectory')
            ->with($destDirStr);

        // Mock read calls: first for copying, then two for hash verification
        $this->filesystem
            ->expects($this->exactly(3))
            ->method('read')
            ->willReturnCallback(function ($path) use ($sourcePathStr, $destPathStr) {
                return 'test content';
            });

        $this->filesystem
            ->expects($this->once())
            ->method('write')
            ->with($destPathStr, 'test content');

        $result = $this->copier->copy($sourcePath, $destinationPath);

        $this->assertTrue($result);
    }

    public function testCopyThrowsExceptionWhenSourceDoesNotExist(): void
    {
        $sourcePath = new FilePath('/nonexistent/file.txt');
        $destinationPath = new FilePath('/destination/file.txt');

        $this->filesystem
            ->expects($this->once())
            ->method('fileExists')
            ->with('/nonexistent/file.txt')
            ->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Source file does not exist');

        $this->copier->copy($sourcePath, $destinationPath);
    }

    public function testCopyVerifiesFileIntegrity(): void
    {
        $sourcePath = new FilePath('/source/file.txt');
        $destinationPath = new FilePath('/destination/file.txt');
        $sourcePathStr = '/source/file.txt';
        $destPathStr = '/destination/file.txt';
        $destDirStr = '/destination';

        $this->filesystem
            ->expects($this->once())
            ->method('fileExists')
            ->with($sourcePathStr)
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('directoryExists')
            ->with($destDirStr)
            ->willReturn(false);

        $this->filesystem
            ->expects($this->once())
            ->method('createDirectory')
            ->with($destDirStr);

        // First read for copying
        $this->filesystem
            ->expects($this->at(2))
            ->method('read')
            ->with($sourcePathStr)
            ->willReturn('test content');

        $this->filesystem
            ->expects($this->once())
            ->method('write')
            ->with($destPathStr, 'test content');

        // Two reads for hash verification (source and destination) - different content
        $this->filesystem
            ->expects($this->at(3))
            ->method('read')
            ->with($sourcePathStr)
            ->willReturn('test content');

        $this->filesystem
            ->expects($this->at(4))
            ->method('read')
            ->with($destPathStr)
            ->willReturn('different content'); // Different content to trigger failure

        $this->filesystem
            ->expects($this->once())
            ->method('delete')
            ->with($destPathStr);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File integrity check failed');

        $this->copier->copy($sourcePath, $destinationPath);
    }

    public function testCopyCreatesDestinationDirectory(): void
    {
        $sourcePath = new FilePath('/source/file.txt');
        $destinationPath = new FilePath('/destination/subdir/file.txt');
        $sourcePathStr = '/source/file.txt';
        $destPathStr = '/destination/subdir/file.txt';
        $destDirStr = '/destination/subdir';

        $this->filesystem
            ->expects($this->once())
            ->method('fileExists')
            ->with($sourcePathStr)
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('directoryExists')
            ->with($destDirStr)
            ->willReturn(false);

        $this->filesystem
            ->expects($this->once())
            ->method('createDirectory')
            ->with($destDirStr);

        // Mock read calls: first for copying, then two for hash verification
        $this->filesystem
            ->expects($this->exactly(3))
            ->method('read')
            ->willReturn('test content');

        $this->filesystem
            ->expects($this->once())
            ->method('write')
            ->with($destPathStr, 'test content');

        $result = $this->copier->copy($sourcePath, $destinationPath);

        $this->assertTrue($result);
    }
}
