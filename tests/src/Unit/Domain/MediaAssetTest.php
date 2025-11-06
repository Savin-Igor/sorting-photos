<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FileCategory;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Domain\ValueObjects\FileType;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;

final class MediaAssetTest extends TestCase
{
    private MediaAsset $asset;

    protected function setUp(): void
    {
        $sourcePath = new FilePath('/source/path/image.jpg');
        $fileType = FileType::IMAGE;
        $metadata = new MediaMeta('image.jpg', 'image/jpeg', 1024, 1920, 1080);
        $date = new MediaDate(Carbon::parse('2021-09-28'));
        $hash = new FileHash('a1b2c3d4e5f6');

        $this->asset = new MediaAsset($sourcePath, $fileType, $metadata, $date, $hash);
    }

    public function testGetSourcePath(): void
    {
        $this->assertInstanceOf(FilePath::class, $this->asset->getSourcePath());
        $this->assertEquals('/source/path/image.jpg', $this->asset->getSourcePath()->getPath());
    }

    public function testGetFileType(): void
    {
        $this->assertEquals(FileType::IMAGE, $this->asset->getFileType());
    }

    public function testGetCategory(): void
    {
        $this->assertEquals(FileCategory::IMAGES, $this->asset->getCategory());
    }

    public function testGetMetadata(): void
    {
        $this->assertInstanceOf(MediaMeta::class, $this->asset->getMetadata());
        $this->assertEquals('image.jpg', $this->asset->getMetadata()->getFileName());
    }

    public function testGetDate(): void
    {
        $this->assertInstanceOf(MediaDate::class, $this->asset->getDate());
        $this->assertEquals(2021, $this->asset->getDate()->getYear());
        $this->assertEquals(9, $this->asset->getDate()->getMonth());
    }

    public function testGetHash(): void
    {
        $this->assertInstanceOf(FileHash::class, $this->asset->getHash());
        $this->assertEquals('a1b2c3d4e5f6', $this->asset->getHash()->getHash());
    }

    public function testGetFileName(): void
    {
        $this->assertEquals('image.jpg', $this->asset->getFileName());
    }

    public function testGetMimeType(): void
    {
        $this->assertEquals('image/jpeg', $this->asset->getMimeType());
    }

    public function testGetFileSize(): void
    {
        $this->assertEquals(1024, $this->asset->getFileSize());
    }

    public function testVideoAsset(): void
    {
        $sourcePath = new FilePath('/source/video.mp4');
        $fileType = FileType::VIDEO;
        $metadata = new MediaMeta('video.mp4', 'video/mp4', 2048);
        $date = new MediaDate(Carbon::parse('2021-10-15'));
        $hash = new FileHash('f6e5d4c3b2a1');

        $asset = new MediaAsset($sourcePath, $fileType, $metadata, $date, $hash);

        $this->assertEquals(FileType::VIDEO, $asset->getFileType());
        $this->assertEquals(FileCategory::VIDEO, $asset->getCategory());
    }

    public function testAudioAsset(): void
    {
        $sourcePath = new FilePath('/source/audio.mp3');
        $fileType = FileType::AUDIO;
        $metadata = new MediaMeta('audio.mp3', 'audio/mpeg', 512, null, null, 180);
        $date = new MediaDate(Carbon::parse('2021-11-20'));
        $hash = new FileHash('1234567890ab');

        $asset = new MediaAsset($sourcePath, $fileType, $metadata, $date, $hash);

        $this->assertEquals(FileType::AUDIO, $asset->getFileType());
        $this->assertEquals(FileCategory::AUDIO, $asset->getCategory());
    }
}
