<?php

declare(strict_types=1);

namespace SortPhotosByDate;

use Carbon\Carbon;
use ReflectionClass;
use PHPUnit\Framework\TestCase;
use SortPhotosByDate\Exception\SortPhotosException;

final class ImageTest extends TestCase
{
    private Image $image;
    private Carbon $dateTime;

    private string $extension = 'jpeg';
    private string $name = 'file-2021-09-28.jpg';

    protected function setUp(): void
    {
        $this->setDateTime(Carbon::parse('2021-09-28'));
        $this->setImage(new Image(__DIR__.'/../source-files/file-2021-09-28.jpg'));
    }

    /**
     * @param Image $image
     */
    public function setImage(Image $image): void
    {
        $this->image = $image;
    }

    /**
     * @param Carbon $dateTime
     */
    public function setDateTime(Carbon $dateTime): void
    {
        $this->dateTime = $dateTime;
    }

    public function testGetDateTime(): void
    {
        $dateTime = $this->image->getDateTime();
        $this->assertEquals($this->dateTime->toDateString(), $dateTime->toDateString());
    }

    public function testGetExtension(): void
    {
        $extention = $this->image->getExtension();
        $this->assertEquals($this->extension, $extention);
    }

    public function testGetName(): void
    {
        $name = $this->image->getName();
        $this->assertEquals($this->name, $name);
    }

    public function testGetType(): void
    {
        $type = $this->image->getType();
        $this->assertEquals(Image::TYPE, $type);
    }

    public function testInvalidFile(): void
    {
        $this->markTestSkipped('There is no file available without metadata');
        $invalidFile = __DIR__.'/../source-files/no_exif.jpg';

        $reflection = new ReflectionClass(SortPhotosException::class);
        /**
         * @psalm-var string $message
         */
        $message = $reflection->getConstant('FAILED_EXTRACT_METADATA');

        $this->expectException(SortPhotosException::class);
        $this->expectErrorMessage(sprintf($message, $invalidFile));

        new Image($invalidFile);
    }
}