<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain\ValueObjects;

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

    public function testFromExtensionImage(): void
    {
        $this->assertEquals(FileType::IMAGE, FileType::fromExtension('test.jpg'));
        $this->assertEquals(FileType::IMAGE, FileType::fromExtension('test.jpeg'));
        $this->assertEquals(FileType::IMAGE, FileType::fromExtension('test.png'));
        $this->assertEquals(FileType::IMAGE, FileType::fromExtension('test.gif'));
        $this->assertEquals(FileType::IMAGE, FileType::fromExtension('test.webp'));
        $this->assertEquals(FileType::IMAGE, FileType::fromExtension('test.heic'));
    }

    public function testFromExtensionVideo(): void
    {
        $this->assertEquals(FileType::VIDEO, FileType::fromExtension('test.mp4'));
        $this->assertEquals(FileType::VIDEO, FileType::fromExtension('test.avi'));
        $this->assertEquals(FileType::VIDEO, FileType::fromExtension('test.mov'));
        $this->assertEquals(FileType::VIDEO, FileType::fromExtension('test.mkv'));
    }

    public function testFromExtensionAudio(): void
    {
        $this->assertEquals(FileType::AUDIO, FileType::fromExtension('test.mp3'));
        $this->assertEquals(FileType::AUDIO, FileType::fromExtension('test.wav'));
        $this->assertEquals(FileType::AUDIO, FileType::fromExtension('test.flac'));
        $this->assertEquals(FileType::AUDIO, FileType::fromExtension('test.aac'));
    }

    public function testFromExtensionDocument(): void
    {
        $this->assertEquals(FileType::DOCUMENT, FileType::fromExtension('test.pdf'));
        $this->assertEquals(FileType::DOCUMENT, FileType::fromExtension('test.doc'));
        $this->assertEquals(FileType::DOCUMENT, FileType::fromExtension('test.docx'));
        $this->assertEquals(FileType::DOCUMENT, FileType::fromExtension('test.xls'));
        $this->assertEquals(FileType::DOCUMENT, FileType::fromExtension('test.xlsx'));
    }

    public function testFromExtensionUnknown(): void
    {
        $this->assertEquals(FileType::OTHER, FileType::fromExtension('test.unknown'));
        $this->assertEquals(FileType::OTHER, FileType::fromExtension('test'));
    }

    public function testDetectSmartWithValidMimeType(): void
    {
        $type = FileType::detectSmart('test.jpg', 'image/jpeg');
        $this->assertEquals(FileType::IMAGE, $type);
    }

    public function testDetectSmartWithGenericMimeType(): void
    {
        // Should fallback to extension
        $type = FileType::detectSmart('test.jpg', 'application/octet-stream');
        $this->assertEquals(FileType::IMAGE, $type);
    }

    public function testDetectSmartWithExifData(): void
    {
        // Even with generic MIME type, EXIF data indicates image
        $type = FileType::detectSmart('test.unknown', 'application/octet-stream', true);
        $this->assertEquals(FileType::IMAGE, $type);
    }

    public function testDetectSmartWithMetadataDuration(): void
    {
        // Duration indicates video
        $type = FileType::detectSmart('test.unknown', 'application/octet-stream', null, ['duration' => 120]);
        $this->assertEquals(FileType::VIDEO, $type);
    }

    public function testDetectSmartWithMetadataWidthHeight(): void
    {
        // Width/height without duration suggests image
        $type = FileType::detectSmart('test.unknown', 'application/octet-stream', null, ['width' => 1920, 'height' => 1080]);
        $this->assertEquals(FileType::IMAGE, $type);
    }

    public function testDetectSmartWithVideoMimeAndDimensions(): void
    {
        // Video MIME type with dimensions should be video
        $type = FileType::detectSmart('test.unknown', 'video/mp4', null, ['width' => 1920, 'height' => 1080]);
        $this->assertEquals(FileType::VIDEO, $type);
    }
}
