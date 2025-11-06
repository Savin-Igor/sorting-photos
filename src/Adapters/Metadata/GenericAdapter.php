<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Metadata;

use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\MimeTypeDetectorInterface;
use SortingPhotosByDate\Ports\MetadataExtractorPort;

final class GenericAdapter implements MetadataExtractorPort
{
    public function __construct(
        private readonly MimeTypeDetectorInterface $mimeTypeDetector,
        private readonly FilesystemPort $filesystem,
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
        $filePathObj = new \SortingPhotosByDate\Domain\ValueObjects\FilePath($filePath);
        if (!$this->filesystem->exists($filePathObj)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        $fileInfo = new \SplFileInfo($filePath);
        $mimeType = $this->mimeTypeDetector->detectMimeType($filePath);
        $fileSize = $this->filesystem->getSize($filePathObj);

        return new MediaMeta(
            $fileInfo->getFilename(),
            $mimeType,
            $fileSize
        );
    }

    #[\Override]
    public function extractDate(string $filePath): MediaDate
    {
        $filePathObj = new \SortingPhotosByDate\Domain\ValueObjects\FilePath($filePath);
        if (!$this->filesystem->exists($filePathObj)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        // Priority: mtime > ctime (using FilesystemPort)
        try {
            $mtime = $this->filesystem->getModificationTime($filePathObj);

            return MediaDate::fromTimestamp($mtime);
        } catch (\Exception $e) {
            // Fallback to ctime if mtime fails (requires native PHP)
            $ctime = filectime($filePath);
            if (false !== $ctime) {
                return MediaDate::fromTimestamp($ctime);
            }
        }

        throw new \RuntimeException("Failed to get file timestamp: {$filePath}");
    }
}
