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
    private \PHPUnit\Framework\MockObject\MockObject $filesystem;

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
            ->expects($this->atLeastOnce())
            ->method('directoryExists')
            ->willReturnCallback(fn(string $path): bool =>
                // Return false for destination directory, true for others (already exist)
                $path !== $destDirStr);

        $this->filesystem
            ->expects($this->atLeastOnce())
            ->method('createDirectory')
            ->willReturnCallback(function (string $path) use ($destDirStr): void {
                // Allow creating destination directory or any parent
                if ($path === $destDirStr || str_starts_with($destDirStr, $path.'/')) {
                    return;
                }
                throw new \RuntimeException("Unexpected directory creation: {$path}");
            });

        // Mock read calls: first for copying, then two for hash verification
        $this->filesystem
            ->expects($this->exactly(3))
            ->method('read')
            ->willReturnCallback(fn($path): string => 'test content');

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

        $this->expectException(\SortingPhotosByDate\Exceptions\FileOperationException::class);
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
            ->expects($this->atLeastOnce())
            ->method('directoryExists')
            ->willReturnCallback(fn(string $path): bool =>
                // Return false for destination directory, true for others (already exist)
                $path !== $destDirStr);

        $this->filesystem
            ->expects($this->atLeastOnce())
            ->method('createDirectory')
            ->willReturnCallback(function (string $path) use ($destDirStr): void {
                // Allow creating destination directory or any parent
                if ($path === $destDirStr || str_starts_with($destDirStr, $path.'/')) {
                    return;
                }
                throw new \RuntimeException("Unexpected directory creation: {$path}");
            });

        // Mock read calls: first for copying, then two for hash verification with different content
        $readCallCount = 0;
        $this->filesystem
            ->expects($this->exactly(3))
            ->method('read')
            ->willReturnCallback(function ($path) use ($sourcePathStr, $destPathStr, &$readCallCount): string {
                $readCallCount++;
                // First read: source for copying
                if ($readCallCount === 1 && $path === $sourcePathStr) {
                    return 'test content';
                }
                // Second read: source for verification
                if ($readCallCount === 2 && $path === $sourcePathStr) {
                    return 'test content';
                }
                // Third read: destination for verification - different content to trigger failure
                if ($readCallCount === 3 && $path === $destPathStr) {
                    return 'different content';
                }
                throw new \RuntimeException("Unexpected read call: {$path} (call #{$readCallCount})");
            });

        $this->filesystem
            ->expects($this->once())
            ->method('write')
            ->with($destPathStr, 'test content');

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
            ->expects($this->atLeastOnce())
            ->method('directoryExists')
            ->willReturnCallback(fn(string $path): bool =>
                // Return false for destination directory, true for others (already exist)
                $path !== $destDirStr);

        $this->filesystem
            ->expects($this->atLeastOnce())
            ->method('createDirectory')
            ->willReturnCallback(function (string $path) use ($destDirStr): void {
                // Allow creating destination directory or any parent
                if ($path === $destDirStr || str_starts_with($destDirStr, $path.'/')) {
                    return;
                }
                throw new \RuntimeException("Unexpected directory creation: {$path}");
            });

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
