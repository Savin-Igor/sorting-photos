<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain\ValueObjects;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

final class FilePathTest extends TestCase
{
    public function testCreateWithValidPath(): void
    {
        $path = new FilePath('/path/to/file.jpg');
        $this->assertEquals('/path/to/file.jpg', $path->getPath());
    }

    public function testGetDirectory(): void
    {
        $path = new FilePath('/path/to/file.jpg');
        $this->assertEquals('/path/to', $path->getDirectory());
    }

    public function testGetBasename(): void
    {
        $path = new FilePath('/path/to/file.jpg');
        $this->assertEquals('file.jpg', $path->getBasename());
    }

    public function testGetExtension(): void
    {
        $path = new FilePath('/path/to/file.jpg');
        $this->assertEquals('jpg', $path->getExtension());
    }

    public function testGetExtensionWithoutDot(): void
    {
        $path = new FilePath('/path/to/file');
        $this->assertEquals('', $path->getExtension());
    }

    public function testGetFilenameWithoutExtension(): void
    {
        $path = new FilePath('/path/to/file.jpg');
        $this->assertEquals('file', $path->getFilenameWithoutExtension());
    }

    public function testToString(): void
    {
        $path = new FilePath('/path/to/file.jpg');
        $this->assertEquals('/path/to/file.jpg', (string) $path);
    }

    public function testEmptyPathThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File path cannot be empty');
        new FilePath('');
    }
}

