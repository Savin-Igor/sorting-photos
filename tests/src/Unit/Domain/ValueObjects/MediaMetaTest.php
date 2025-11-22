<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain\ValueObjects;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;

final class MediaMetaTest extends TestCase
{
    public function testCreateWithRequiredFields(): void
    {
        $meta = new MediaMeta(
            'test.jpg',
            'image/jpeg',
            1024
        );

        $this->assertEquals('test.jpg', $meta->getFileName());
        $this->assertEquals('image/jpeg', $meta->getMimeType());
        $this->assertEquals(1024, $meta->getFileSize());
    }

    public function testCreateWithAllFields(): void
    {
        $meta = new MediaMeta(
            'test.jpg',
            'image/jpeg',
            1024,
            1920,
            1080,
            120,
            'Artist Name',
            'Song Title',
            'Album Name',
            ['custom' => 'value']
        );

        $this->assertEquals('test.jpg', $meta->getFileName());
        $this->assertEquals('image/jpeg', $meta->getMimeType());
        $this->assertEquals(1024, $meta->getFileSize());
        $this->assertEquals(1920, $meta->getWidth());
        $this->assertEquals(1080, $meta->getHeight());
        $this->assertEquals(120, $meta->getDuration());
        $this->assertEquals('Artist Name', $meta->getArtist());
        $this->assertEquals('Song Title', $meta->getTitle());
        $this->assertEquals('Album Name', $meta->getAlbum());
        $this->assertEquals(['custom' => 'value'], $meta->getAdditionalMetadata());
    }

    public function testGetMetadata(): void
    {
        $meta = new MediaMeta(
            'test.jpg',
            'image/jpeg',
            1024,
            null,
            null,
            null,
            null,
            null,
            null,
            ['key1' => 'value1', 'key2' => 'value2']
        );

        $this->assertEquals('value1', $meta->getMetadata('key1'));
        $this->assertEquals('value2', $meta->getMetadata('key2'));
        $this->assertNull($meta->getMetadata('non-existent'));
        $this->assertEquals('default', $meta->getMetadata('non-existent', 'default'));
    }

    public function testHasMetadata(): void
    {
        $meta = new MediaMeta(
            'test.jpg',
            'image/jpeg',
            1024,
            null,
            null,
            null,
            null,
            null,
            null,
            ['key1' => 'value1']
        );

        $this->assertTrue($meta->hasMetadata('key1'));
        $this->assertFalse($meta->hasMetadata('non-existent'));
    }

    public function testEmptyFileNameThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File name cannot be empty');
        new MediaMeta('', 'image/jpeg', 1024);
    }

    public function testNegativeFileSizeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File size must be non-negative');
        new MediaMeta('test.jpg', 'image/jpeg', -1);
    }
}
