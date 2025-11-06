<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Filesystem;

use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\MimeTypeDetectorInterface;

/**
 * Adapter wrapper for League MIME Type Detection library.
 * Implements MimeTypeDetectorInterface using vendor library.
 */
final class MimeTypeDetectorAdapter implements MimeTypeDetectorInterface
{
    private readonly MimeTypeDetector $detector;

    public function __construct(
        ?MimeTypeDetector $detector = null,
        private readonly ?FilesystemPort $filesystem = null,
    ) {
        $this->detector = $detector ?? new FinfoMimeTypeDetector();
    }

    #[\Override]
    public function detectMimeType(string $filePath): string
    {
        // Use FilesystemPort if available, otherwise fallback to native check
        if (null !== $this->filesystem) {
            $filePathObj = new \SortingPhotosByDate\Domain\ValueObjects\FilePath($filePath);
            if (!$this->filesystem->exists($filePathObj)) {
                return 'application/octet-stream';
            }
        } elseif (!file_exists($filePath)) {
            return 'application/octet-stream';
        }

        $mimeType = $this->detector->detectMimeTypeFromFile($filePath);

        return $mimeType ?? 'application/octet-stream';
    }

    #[\Override]
    public function detectMimeTypeFromBuffer(string $contents): string
    {
        $mimeType = $this->detector->detectMimeTypeFromBuffer($contents);

        return $mimeType ?? 'application/octet-stream';
    }
}
