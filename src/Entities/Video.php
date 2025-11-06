<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Entities;

use Carbon\Carbon;
use SortingPhotosByDate\Contracts\FileInterface;

final class Video implements FileInterface
{
    private string $name;
    private string $extension;
    private Carbon $dateTime;
    public const TYPE = 'video';

    public function __construct(string $filePath)
    {
        $fileInfo = new \SplFileInfo($filePath);

        $this->name = $fileInfo->getBasename();
        $this->extension = $fileInfo->getExtension();

        if (preg_match('/(?<dateTime>\d{8})/iu', $filePath, $matches)) {
            $this->dateTime = Carbon::parse($matches['dateTime']);
        } else {
            $this->dateTime = Carbon::parse((string) $fileInfo->getMTime());
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function getDateTime(): Carbon
    {
        return $this->dateTime;
    }

    public function getType(): string
    {
        return self::TYPE;
    }
}
