<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Metadata;

use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Ports\MetadataExtractorPort;

final class GenericAdapter implements MetadataExtractorPort
{
    public function supports(string $mimeType): bool
    {
        // Generic adapter supports all MIME types as fallback
        return true;
    }

    public function extract(string $filePath): MediaMeta
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        $fileInfo = new \SplFileInfo($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        return new MediaMeta(
            $fileInfo->getFilename(),
            $mimeType,
            $fileInfo->getSize()
        );
    }

    public function extractDate(string $filePath): MediaDate
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        // Priority: mtime > ctime
        $mtime = filemtime($filePath);
        if ($mtime !== false) {
            return MediaDate::fromTimestamp($mtime);
        }

        $ctime = filectime($filePath);
        if ($ctime !== false) {
            return MediaDate::fromTimestamp($ctime);
        }

        throw new \RuntimeException("Failed to get file timestamp: {$filePath}");
    }
}
