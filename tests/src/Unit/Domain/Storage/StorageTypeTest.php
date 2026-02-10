<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain\Storage;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\Storage\StorageType;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

final class StorageTypeTest extends TestCase
{
    public function testFromDsnScheme(): void
    {
        $this->assertEquals(StorageType::LOCAL, StorageType::fromDsnScheme('local'));
        $this->assertEquals(StorageType::LOCAL, StorageType::fromDsnScheme('file'));
        $this->assertEquals(StorageType::GOOGLE_PHOTOS, StorageType::fromDsnScheme('google-photos'));
        $this->assertEquals(StorageType::GOOGLE_PHOTOS, StorageType::fromDsnScheme('gphotos'));
        $this->assertEquals(StorageType::DROPBOX, StorageType::fromDsnScheme('dropbox'));
        $this->assertEquals(StorageType::S3, StorageType::fromDsnScheme('s3'));
        $this->assertEquals(StorageType::S3, StorageType::fromDsnScheme('aws-s3'));
    }

    public function testFromDsnSchemeInvalidThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown storage scheme: invalid');
        StorageType::fromDsnScheme('invalid');
    }

    public function testToDsnScheme(): void
    {
        $this->assertEquals('local', StorageType::LOCAL->toDsnScheme());
        $this->assertEquals('google-photos', StorageType::GOOGLE_PHOTOS->toDsnScheme());
        $this->assertEquals('dropbox', StorageType::DROPBOX->toDsnScheme());
        $this->assertEquals('s3', StorageType::S3->toDsnScheme());
    }
}

