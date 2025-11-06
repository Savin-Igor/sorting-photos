<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Infrastructure\Metadata;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Infrastructure\Metadata\FilenameDateExtractor;

final class FilenameDateExtractorTest extends TestCase
{
    private FilenameDateExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FilenameDateExtractor();
    }

    public function testExtractDateTimeSeparated(): void
    {
        $date = $this->extractor->extract('2014-08-02_04-01-34_file.jpeg');
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame(2014, $date->year);
        $this->assertSame(8, $date->month);
        $this->assertSame(2, $date->day);
        $this->assertSame(4, $date->hour);
        $this->assertSame(1, $date->minute);
        $this->assertSame(34, $date->second);
    }

    public function testExtractDateTimeCompact(): void
    {
        $date = $this->extractor->extract('2014-08-02_040134_file.jpeg');
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame(2014, $date->year);
        $this->assertSame(8, $date->month);
        $this->assertSame(2, $date->day);
        $this->assertSame(4, $date->hour);
        $this->assertSame(1, $date->minute);
        $this->assertSame(34, $date->second);
    }

    public function testExtractImgFormat(): void
    {
        $date = $this->extractor->extract('IMG_20200520_171804.jpg');
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame(2020, $date->year);
        $this->assertSame(5, $date->month);
        $this->assertSame(20, $date->day);
        $this->assertSame(17, $date->hour);
        $this->assertSame(18, $date->minute);
        $this->assertSame(4, $date->second);
    }

    public function testExtractDateOnly(): void
    {
        $date = $this->extractor->extract('2014-09-06_file.jpeg');
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame(2014, $date->year);
        $this->assertSame(9, $date->month);
        $this->assertSame(6, $date->day);
    }

    public function testExtractDateCompact(): void
    {
        $date = $this->extractor->extract('20140802_file.jpeg');
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame(2014, $date->year);
        $this->assertSame(8, $date->month);
        $this->assertSame(2, $date->day);
    }

    public function testExtractTimestampMs(): void
    {
        $date = $this->extractor->extract('PhotoGrid_1404648109915~2.jpg');
        $this->assertInstanceOf(Carbon::class, $date);
        // 1404648109915 ms = 2014-07-06 12:01:49 UTC
        $this->assertSame(2014, $date->year);
        $this->assertSame(7, $date->month);
    }

    public function testExtractImgTimestamp(): void
    {
        $date = $this->extractor->extract('img1405178243028_1.jpg');
        $this->assertInstanceOf(Carbon::class, $date);
        // 1405178243028 ms = 2014-07-12 15:17:23 UTC
        $this->assertSame(2014, $date->year);
        $this->assertSame(7, $date->month);
    }

    public function testExtractDateDDMMYYYY(): void
    {
        $date = $this->extractor->extract('03.11.2007.jpg');
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame(2007, $date->year);
        $this->assertSame(11, $date->month);
        $this->assertSame(3, $date->day);
    }

    public function testExtractRussianFormat(): void
    {
        $date = $this->extractor->extract('Снимок экрана от 2025-10-27 17-18-41.png');
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame(2025, $date->year);
        $this->assertSame(10, $date->month);
        $this->assertSame(27, $date->day);
        $this->assertSame(17, $date->hour);
        $this->assertSame(18, $date->minute);
        $this->assertSame(41, $date->second);
    }

    public function testExtractInvalidYear(): void
    {
        // Year 7176 is invalid (outside 1900-2100 range)
        $date = $this->extractor->extract('7176-04-26_07-14-29_file.jpeg');
        $this->assertNull($date);
    }

    public function testExtractNoDate(): void
    {
        $date = $this->extractor->extract('IMG_0224.jpg');
        $this->assertNull($date);
    }

    public function testExtractFullPath(): void
    {
        $date = $this->extractor->extract('/var/data/source/2014-08-02_04-01-34_file.jpeg');
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame(2014, $date->year);
        $this->assertSame(8, $date->month);
    }
}

