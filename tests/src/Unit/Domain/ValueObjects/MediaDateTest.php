<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain\ValueObjects;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;

final class MediaDateTest extends TestCase
{
    public function testGetDateTime(): void
    {
        $carbon = Carbon::parse('2021-09-28 12:30:45');
        $mediaDate = new MediaDate($carbon);
        $this->assertEquals($carbon, $mediaDate->getDateTime());
    }

    public function testGetYear(): void
    {
        $carbon = Carbon::parse('2021-09-28');
        $mediaDate = new MediaDate($carbon);
        $this->assertEquals(2021, $mediaDate->getYear());
    }

    public function testGetMonth(): void
    {
        $carbon = Carbon::parse('2021-09-28');
        $mediaDate = new MediaDate($carbon);
        $this->assertEquals(9, $mediaDate->getMonth());
    }

    public function testGetDay(): void
    {
        $carbon = Carbon::parse('2021-09-28');
        $mediaDate = new MediaDate($carbon);
        $this->assertEquals(28, $mediaDate->getDay());
    }

    public function testFormat(): void
    {
        $carbon = Carbon::parse('2021-09-28 12:30:45');
        $mediaDate = new MediaDate($carbon);
        $this->assertEquals('2021-09-28', $mediaDate->format('Y-m-d'));
    }

    public function testGetYearMonth(): void
    {
        $carbon = Carbon::parse('2021-09-28');
        $mediaDate = new MediaDate($carbon);
        $this->assertEquals('2021-09', $mediaDate->getYearMonth());
    }

    public function testGetYearMonthPath(): void
    {
        $carbon = Carbon::parse('2021-09-28');
        $mediaDate = new MediaDate($carbon);
        $this->assertEquals('2021/09', $mediaDate->getYearMonthPath());
    }

    public function testEquals(): void
    {
        $carbon1 = Carbon::parse('2021-09-28 12:30:45');
        $carbon2 = Carbon::parse('2021-09-28 12:30:45');
        $carbon3 = Carbon::parse('2021-09-29 12:30:45');

        $mediaDate1 = new MediaDate($carbon1);
        $mediaDate2 = new MediaDate($carbon2);
        $mediaDate3 = new MediaDate($carbon3);

        $this->assertTrue($mediaDate1->equals($mediaDate2));
        $this->assertFalse($mediaDate1->equals($mediaDate3));
    }

    public function testIsBefore(): void
    {
        $carbon1 = Carbon::parse('2021-09-28');
        $carbon2 = Carbon::parse('2021-09-29');

        $mediaDate1 = new MediaDate($carbon1);
        $mediaDate2 = new MediaDate($carbon2);

        $this->assertTrue($mediaDate1->isBefore($mediaDate2));
        $this->assertFalse($mediaDate2->isBefore($mediaDate1));
    }

    public function testIsAfter(): void
    {
        $carbon1 = Carbon::parse('2021-09-28');
        $carbon2 = Carbon::parse('2021-09-29');

        $mediaDate1 = new MediaDate($carbon1);
        $mediaDate2 = new MediaDate($carbon2);

        $this->assertTrue($mediaDate2->isAfter($mediaDate1));
        $this->assertFalse($mediaDate1->isAfter($mediaDate2));
    }

    public function testFromTimestamp(): void
    {
        $timestamp = Carbon::parse('2021-09-28')->timestamp;
        $mediaDate = MediaDate::fromTimestamp($timestamp);
        $this->assertEquals(2021, $mediaDate->getYear());
        $this->assertEquals(9, $mediaDate->getMonth());
        $this->assertEquals(28, $mediaDate->getDay());
    }

    public function testFromString(): void
    {
        $mediaDate = MediaDate::fromString('2021-09-28 12:30:45');
        $this->assertEquals(2021, $mediaDate->getYear());
        $this->assertEquals(9, $mediaDate->getMonth());
        $this->assertEquals(28, $mediaDate->getDay());
    }

    public function testNow(): void
    {
        $mediaDate = MediaDate::now();
        $this->assertInstanceOf(MediaDate::class, $mediaDate);
        $this->assertInstanceOf(Carbon::class, $mediaDate->getDateTime());
    }
}
