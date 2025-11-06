<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain\Policies;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\Policies\DateTypePolicy;
use SortingPhotosByDate\Domain\ValueObjects\FileCategory;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Domain\ValueObjects\FileType;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;

final class DateTypePolicyTest extends TestCase
{
    private DateTypePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new DateTypePolicy('/destination');
    }

    public function testOrganizeImage(): void
    {
        $asset = $this->createAsset(FileType::IMAGE, '2021-09-28', 'image.jpg');
        $path = $this->policy->organize($asset);

        $this->assertInstanceOf(FilePath::class, $path);
        $this->assertEquals('/destination/2021/09/images/image.jpg', $path->getPath());
    }

    public function testOrganizeVideo(): void
    {
        $asset = $this->createAsset(FileType::VIDEO, '2021-10-15', 'video.mp4');
        $path = $this->policy->organize($asset);

        $this->assertEquals('/destination/2021/10/video/video.mp4', $path->getPath());
    }

    public function testOrganizeAudio(): void
    {
        $asset = $this->createAsset(FileType::AUDIO, '2021-11-20', 'audio.mp3');
        $path = $this->policy->organize($asset);

        $this->assertEquals('/destination/2021/11/audio/audio.mp3', $path->getPath());
    }

    public function testOrganizeWithSingleDigitMonth(): void
    {
        $asset = $this->createAsset(FileType::IMAGE, '2021-01-05', 'image.jpg');
        $path = $this->policy->organize($asset);

        $this->assertEquals('/destination/2021/01/images/image.jpg', $path->getPath());
    }

    public function testEmptyBaseDirectoryThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Base directory cannot be empty');
        new DateTypePolicy('');
    }

    private function createAsset(FileType $type, string $date, string $fileName): MediaAsset
    {
        $sourcePath = new FilePath('/source/' . $fileName);
        $metadata = new MediaMeta($fileName, 'test/mime', 1024);
        $mediaDate = new MediaDate(Carbon::parse($date));
        $hash = new FileHash('a1b2c3d4e5f6');

        return new MediaAsset($sourcePath, $type, $metadata, $mediaDate, $hash);
    }
}

