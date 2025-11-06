<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Metadata;

use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Infrastructure\Filesystem\MimeTypeDetectorWrapper;
use SortingPhotosByDate\Ports\MetadataExtractorPort;

final class GenericAdapter implements MetadataExtractorPort
{
    public function __construct(
        private readonly MimeTypeDetectorWrapper $mimeTypeDetector,
    ) {
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        // Generic adapter supports all MIME types as fallback
        return true;
    }

    #[\Override]
    public function extract(string $filePath): MediaMeta
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        $fileInfo = new \SplFileInfo($filePath);
        $mimeType = $this->mimeTypeDetector->detectMimeType($filePath);

        return new MediaMeta(
            $fileInfo->getFilename(),
            $mimeType,
            $fileInfo->getSize()
        );
    }

    #[\Override]
    public function extractDate(string $filePath): MediaDate
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        // Priority: mtime > ctime
        $mtime = filemtime($filePath);
        if (false !== $mtime) {
            return MediaDate::fromTimestamp($mtime);
        }

        $ctime = filectime($filePath);
        if (false !== $ctime) {
            return MediaDate::fromTimestamp($ctime);
        }

        throw new \RuntimeException("Failed to get file timestamp: {$filePath}");
    }
}
