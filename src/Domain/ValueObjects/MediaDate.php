<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\ValueObjects;

use Carbon\Carbon;

final class MediaDate
{
    public function __construct(
        private Carbon $dateTime
    ) {
    }

    public function getDateTime(): Carbon
    {
        return $this->dateTime;
    }

    public function getYear(): int
    {
        return $this->dateTime->year;
    }

    public function getMonth(): int
    {
        return $this->dateTime->month;
    }

    public function getDay(): int
    {
        return $this->dateTime->day;
    }

    public function format(string $format): string
    {
        return $this->dateTime->format($format);
    }

    public function getYearMonth(): string
    {
        return $this->dateTime->format('Y-m');
    }

    public function getYearMonthPath(): string
    {
        return $this->dateTime->format('Y/m');
    }

    public function equals(self $other): bool
    {
        return $this->dateTime->equalTo($other->dateTime);
    }

    public function isBefore(self $other): bool
    {
        return $this->dateTime->isBefore($other->dateTime);
    }

    public function isAfter(self $other): bool
    {
        return $this->dateTime->isAfter($other->dateTime);
    }

    public static function fromTimestamp(int $timestamp): self
    {
        return new self(Carbon::createFromTimestamp($timestamp));
    }

    public static function fromString(string $dateTime): self
    {
        return new self(Carbon::parse($dateTime));
    }

    public static function now(): self
    {
        return new self(Carbon::now());
    }
}

