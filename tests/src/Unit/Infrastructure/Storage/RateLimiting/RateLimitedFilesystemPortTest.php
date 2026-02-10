<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Infrastructure\Storage\RateLimiting;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Storage\RateLimiting\RateLimitedFilesystemPort;
use SortingPhotosByDate\Infrastructure\Storage\RateLimiting\TokenBucketRateLimiter;
use SortingPhotosByDate\Ports\FilesystemPort;

final class RateLimitedFilesystemPortTest extends TestCase
{
    public function testRateLimitingApplied(): void
    {
        $filesystem = $this->createMock(FilesystemPort::class);
        $rateLimiter = new TokenBucketRateLimiter(2, 1); // 2 requests per second

        $bridge = new RateLimitedFilesystemPort($filesystem, $rateLimiter);

        $filePath = new FilePath('/test/file.txt');

        $filesystem->expects($this->exactly(3))
            ->method('exists')
            ->with($filePath)
            ->willReturn(true);

        $start = microtime(true);

        // First request should not wait
        $bridge->exists($filePath);
        $time1 = microtime(true) - $start;

        // Second request should not wait
        $bridge->exists($filePath);
        $time2 = microtime(true) - $start;

        // Third request should wait
        $bridge->exists($filePath);
        $time3 = microtime(true) - $start;

        $this->assertLessThan(0.1, $time1); // First request should be fast
        $this->assertLessThan(0.1, $time2); // Second request should be fast
        $this->assertGreaterThan(0.9, $time3); // Third request should wait ~1 second
    }

    public function testAllMethodsCallRateLimiter(): void
    {
        $filesystem = $this->createMock(FilesystemPort::class);
        $rateLimiter = $this->createMock(\SortingPhotosByDate\Infrastructure\Storage\RateLimiting\RateLimiter::class);

        $rateLimiter->expects($this->exactly(14))
            ->method('waitIfNeeded');

        $bridge = new RateLimitedFilesystemPort($filesystem, $rateLimiter);

        $filePath = new FilePath('/test/file.txt');

        $filesystem->method('copyWithMetadata')->willReturn(true);
        $filesystem->method('ensureDirectory')->willReturn(true);
        $filesystem->method('delete')->willReturn(true);
        $filesystem->method('exists')->willReturn(true);
        $filesystem->method('getSize')->willReturn(1024);
        $filesystem->method('getPermissions')->willReturn(0644);
        $filesystem->method('setPermissions')->willReturn(true);
        $filesystem->method('getModificationTime')->willReturn(time());
        $filesystem->method('setModificationTime')->willReturn(true);
        $filesystem->method('getAccessTime')->willReturn(time());
        $filesystem->method('setTimestamps')->willReturn(true);
        $filesystem->method('read')->willReturn('content');
        $filesystem->method('calculateHash')->willReturn('hash');
        // write() returns void, so no need to mock return value

        $bridge->copyWithMetadata($filePath, $filePath);
        $bridge->ensureDirectory($filePath);
        $bridge->delete($filePath);
        $bridge->exists($filePath);
        $bridge->getSize($filePath);
        $bridge->getPermissions($filePath);
        $bridge->setPermissions($filePath, 0644);
        $bridge->getModificationTime($filePath);
        $bridge->setModificationTime($filePath, time());
        $bridge->getAccessTime($filePath);
        $bridge->setTimestamps($filePath, time(), time());
        $bridge->read($filePath);
        $bridge->calculateHash($filePath);
        $bridge->write($filePath, 'content');
    }
}

