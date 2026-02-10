<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain\Storage;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\Storage\StorageMetadata;

final class StorageMetadataTest extends TestCase
{
    public function testCreateWithValidData(): void
    {
        $metadata = new StorageMetadata(
            size: 1024,
            modifiedTime: 1234567890,
            createdTime: 1234567800,
            mimeType: 'image/jpeg',
            etag: 'etag123',
            customAttributes: ['key' => 'value']
        );

        $this->assertEquals(1024, $metadata->getSize());
        $this->assertEquals(1234567890, $metadata->getModifiedTime());
        $this->assertEquals(1234567800, $metadata->getCreatedTime());
        $this->assertEquals('image/jpeg', $metadata->getMimeType());
        $this->assertEquals('etag123', $metadata->getEtag());
        $this->assertEquals(['key' => 'value'], $metadata->getCustomAttributes());
    }

    public function testCreateWithDefaults(): void
    {
        $metadata = new StorageMetadata();

        $this->assertNull($metadata->getSize());
        $this->assertNull($metadata->getModifiedTime());
        $this->assertNull($metadata->getCreatedTime());
        $this->assertNull($metadata->getMimeType());
        $this->assertNull($metadata->getEtag());
        $this->assertEquals([], $metadata->getCustomAttributes());
    }

    public function testGetCustomAttribute(): void
    {
        $metadata = new StorageMetadata(
            customAttributes: ['key1' => 'value1', 'key2' => 'value2']
        );

        $this->assertEquals('value1', $metadata->getCustomAttribute('key1'));
        $this->assertEquals('value2', $metadata->getCustomAttribute('key2'));
        $this->assertNull($metadata->getCustomAttribute('key3'));
        $this->assertEquals('default', $metadata->getCustomAttribute('key3', 'default'));
    }

    public function testWithSize(): void
    {
        $metadata = new StorageMetadata();
        $newMetadata = $metadata->withSize(2048);

        $this->assertNull($metadata->getSize());
        $this->assertEquals(2048, $newMetadata->getSize());
    }

    public function testWithModifiedTime(): void
    {
        $metadata = new StorageMetadata();
        $newMetadata = $metadata->withModifiedTime(1234567890);

        $this->assertNull($metadata->getModifiedTime());
        $this->assertEquals(1234567890, $newMetadata->getModifiedTime());
    }

    public function testWithMimeType(): void
    {
        $metadata = new StorageMetadata();
        $newMetadata = $metadata->withMimeType('image/png');

        $this->assertNull($metadata->getMimeType());
        $this->assertEquals('image/png', $newMetadata->getMimeType());
    }

    public function testWithCustomAttribute(): void
    {
        $metadata = new StorageMetadata();
        $newMetadata = $metadata->withCustomAttribute('key', 'value');

        $this->assertEquals([], $metadata->getCustomAttributes());
        $this->assertEquals('value', $newMetadata->getCustomAttribute('key'));
    }
}

