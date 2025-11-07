<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Storage;

/**
 * Storage type enumeration.
 */
enum StorageType: string
{
    case LOCAL = 'local';
    case GOOGLE_PHOTOS = 'google-photos';
    case DROPBOX = 'dropbox';
    case S3 = 's3';

    /**
     * Get storage type from DSN scheme.
     */
    public static function fromDsnScheme(string $scheme): self
    {
        return match ($scheme) {
            'local', 'file' => self::LOCAL,
            'google-photos', 'gphotos' => self::GOOGLE_PHOTOS,
            'dropbox' => self::DROPBOX,
            's3', 'aws-s3' => self::S3,
            default => throw new \InvalidArgumentException("Unknown storage scheme: {$scheme}"),
        };
    }

    /**
     * Get DSN scheme for storage type.
     */
    public function toDsnScheme(): string
    {
        return match ($this) {
            self::LOCAL => 'local',
            self::GOOGLE_PHOTOS => 'google-photos',
            self::DROPBOX => 'dropbox',
            self::S3 => 's3',
        };
    }
}
