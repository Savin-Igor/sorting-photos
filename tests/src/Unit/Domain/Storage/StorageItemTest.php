<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain\Storage;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\Storage\StorageItem;
use SortingPhotosByDate\Domain\Storage\StorageType;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

final class StorageItemTest extends TestCase
{
    public function testCreateWithValidData(): void
    {
        $item = new StorageItem(
            identifier: 'test-id',
            type: StorageType::LOCAL,
            path: '/path/to/file.jpg',
            size: 1024,
            modifiedTime: 1234567890
        );

        $this->assertEquals('test-id', $item->getIdentifier());
        $this->assertEquals(StorageType::LOCAL, $item->getType());
        $this->assertEquals('/path/to/file.jpg', $item->getPath());
        $this->assertEquals(1024, $item->getSize());
        $this->assertEquals(1234567890, $item->getModifiedTime());
    }

    public function testFromFilePath(): void
    {
        $filePath = new FilePath('/path/to/file.jpg');
        $item = StorageItem::fromFilePath($filePath, StorageType::LOCAL);

        $this->assertEquals('/path/to/file.jpg', $item->getIdentifier());
        $this->assertEquals('/path/to/file.jpg', $item->getPath());
        $this->assertEquals(StorageType::LOCAL, $item->getType());
    }

    public function testToFilePath(): void
    {
        $item = new StorageItem(
            identifier: '/path/to/file.jpg',
            type: StorageType::LOCAL,
            path: '/path/to/file.jpg'
        );

        $filePath = $item->toFilePath();
        $this->assertEquals('/path/to/file.jpg', $filePath->getPath());
    }

    public function testWithSize(): void
    {
        $item = new StorageItem(
            identifier: 'test-id',
            type: StorageType::LOCAL,
            path: '/path/to/file.jpg'
        );

        $newItem = $item->withSize(2048);
        $this->assertNull($item->getSize());
        $this->assertEquals(2048, $newItem->getSize());
    }

    public function testWithModifiedTime(): void
    {
        $item = new StorageItem(
            identifier: 'test-id',
            type: StorageType::LOCAL,
            path: '/path/to/file.jpg'
        );

        $newItem = $item->withModifiedTime(1234567890);
        $this->assertNull($item->getModifiedTime());
        $this->assertEquals(1234567890, $newItem->getModifiedTime());
    }

    public function testEmptyIdentifierThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Storage item identifier cannot be empty');
        new StorageItem('', StorageType::LOCAL, '/path/to/file.jpg');
    }

    public function testEmptyPathThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Storage item path cannot be empty');
        new StorageItem('test-id', StorageType::LOCAL, '');
    }
}

