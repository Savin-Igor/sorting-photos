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
final readonly class MimeTypeDetectorAdapter implements MimeTypeDetectorInterface
{
    public function __construct(private ?MimeTypeDetector $detector = new FinfoMimeTypeDetector(), private ?FilesystemPort $filesystem = null)
    {
    }

    #[\Override]
    public function detectMimeType(string $filePath): string
    {
        // Use FilesystemPort if available, otherwise fallback to native check
        if ($this->filesystem instanceof FilesystemPort) {
            $filePathObj = new \SortingPhotosByDate\Domain\ValueObjects\FilePath($filePath);
            if (!$this->filesystem->exists($filePathObj)) {
                return 'application/octet-stream';
            }
        } elseif (!file_exists($filePath)) {
            return 'application/octet-stream';
        }

        if (!$this->detector instanceof MimeTypeDetector) {
            return 'application/octet-stream';
        }

        $mimeType = $this->detector->detectMimeTypeFromFile($filePath);

        return $mimeType ?? 'application/octet-stream';
    }

    #[\Override]
    public function detectMimeTypeFromBuffer(string $contents): string
    {
        if (!$this->detector instanceof MimeTypeDetector) {
            return 'application/octet-stream';
        }

        $mimeType = $this->detector->detectMimeTypeFromBuffer($contents);

        return $mimeType ?? 'application/octet-stream';
    }
}
