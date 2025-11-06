<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\ValueObjects;

enum FileType: string
{
    case IMAGE = 'image';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case DOCUMENT = 'document';
    case OTHER = 'other';

    public function toCategory(): FileCategory
    {
        return match ($this) {
            self::IMAGE => FileCategory::IMAGES,
            self::VIDEO => FileCategory::VIDEO,
            self::AUDIO => FileCategory::AUDIO,
            self::DOCUMENT, self::OTHER => FileCategory::OTHER,
        };
    }

    public static function fromMimeType(string $mimeType): self
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => self::IMAGE,
            str_starts_with($mimeType, 'video/') => self::VIDEO,
            str_starts_with($mimeType, 'audio/') => self::AUDIO,
            str_starts_with($mimeType, 'application/pdf'),
            str_starts_with($mimeType, 'application/msword'),
            str_starts_with($mimeType, 'application/vnd.ms-excel'),
            str_starts_with($mimeType, 'application/vnd.ms-powerpoint'),
            str_starts_with($mimeType, 'application/vnd.openxmlformats') => self::DOCUMENT,
            default => self::OTHER,
        };
    }
}
