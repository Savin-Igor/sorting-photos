<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain\ValueObjects;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\ValueObjects\FileCategory;
use SortingPhotosByDate\Domain\ValueObjects\FileType;

final class FileTypeTest extends TestCase
{
    public function testImageType(): void
    {
        $type = FileType::IMAGE;
        $this->assertEquals('image', $type->value);
        $this->assertEquals(FileCategory::IMAGES, $type->toCategory());
    }

    public function testVideoType(): void
    {
        $type = FileType::VIDEO;
        $this->assertEquals('video', $type->value);
        $this->assertEquals(FileCategory::VIDEO, $type->toCategory());
    }

    public function testAudioType(): void
    {
        $type = FileType::AUDIO;
        $this->assertEquals('audio', $type->value);
        $this->assertEquals(FileCategory::AUDIO, $type->toCategory());
    }

    public function testDocumentType(): void
    {
        $type = FileType::DOCUMENT;
        $this->assertEquals('document', $type->value);
        $this->assertEquals(FileCategory::OTHER, $type->toCategory());
    }

    public function testOtherType(): void
    {
        $type = FileType::OTHER;
        $this->assertEquals('other', $type->value);
        $this->assertEquals(FileCategory::OTHER, $type->toCategory());
    }

    public function testFromMimeTypeImage(): void
    {
        $type = FileType::fromMimeType('image/jpeg');
        $this->assertEquals(FileType::IMAGE, $type);
    }

    public function testFromMimeTypeVideo(): void
    {
        $type = FileType::fromMimeType('video/mp4');
        $this->assertEquals(FileType::VIDEO, $type);
    }

    public function testFromMimeTypeAudio(): void
    {
        $type = FileType::fromMimeType('audio/mpeg');
        $this->assertEquals(FileType::AUDIO, $type);
    }

    public function testFromMimeTypePdf(): void
    {
        $type = FileType::fromMimeType('application/pdf');
        $this->assertEquals(FileType::DOCUMENT, $type);
    }

    public function testFromMimeTypeWord(): void
    {
        $type = FileType::fromMimeType('application/msword');
        $this->assertEquals(FileType::DOCUMENT, $type);
    }

    public function testFromMimeTypeExcel(): void
    {
        $type = FileType::fromMimeType('application/vnd.ms-excel');
        $this->assertEquals(FileType::DOCUMENT, $type);
    }

    public function testFromMimeTypePowerPoint(): void
    {
        $type = FileType::fromMimeType('application/vnd.ms-powerpoint');
        $this->assertEquals(FileType::DOCUMENT, $type);
    }

    public function testFromMimeTypeOpenXml(): void
    {
        $type = FileType::fromMimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertEquals(FileType::DOCUMENT, $type);
    }

    public function testFromMimeTypeUnknown(): void
    {
        $type = FileType::fromMimeType('application/unknown');
        $this->assertEquals(FileType::OTHER, $type);
    }
}

