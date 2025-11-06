<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Metadata;

use Carbon\Carbon;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Ports\AudioVideoMetadataAnalyzerInterface;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\MimeTypeDetectorInterface;
use SortingPhotosByDate\Ports\MetadataExtractorPort;

final readonly class GetId3Adapter implements MetadataExtractorPort
{
    public function __construct(
        private MimeTypeDetectorInterface $mimeTypeDetector,
        private AudioVideoMetadataAnalyzerInterface $metadataAnalyzer,
        private FilesystemPort $filesystem,
    ) {
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'audio/') || str_starts_with($mimeType, 'video/');
    }

    #[\Override]
    public function extract(string $filePath): MediaMeta
    {
        $filePathObj = new \SortingPhotosByDate\Domain\ValueObjects\FilePath($filePath);
        if (!$this->filesystem->exists($filePathObj)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        /** @var array<string, mixed> $fileInfo */
        $fileInfo = $this->metadataAnalyzer->analyze($filePath);
        $fileInfoObj = new \SplFileInfo($filePath);
        $mimeType = $this->mimeTypeDetector->detectMimeType($filePath);

        $width = null;
        $height = null;
        if (isset($fileInfo['video']['resolution_x'], $fileInfo['video']['resolution_y'])) {
            $width = (int) $fileInfo['video']['resolution_x'];
            $height = (int) $fileInfo['video']['resolution_y'];
        }

        $duration = null;
        if (isset($fileInfo['playtime_seconds']) && is_numeric($fileInfo['playtime_seconds'])) {
            $duration = (int) round((float) $fileInfo['playtime_seconds']);
        }

        $artist = null;
        $title = null;
        $album = null;

        if (isset($fileInfo['tags']) && is_array($fileInfo['tags'])) {
            /** @var array<string, mixed> $tags */
            $tags = $fileInfo['tags'];
            if (isset($tags['id3v2']['artist'][0])) {
                $artist = (string) $tags['id3v2']['artist'][0];
            } elseif (isset($tags['id3v1']['artist'][0])) {
                $artist = (string) $tags['id3v1']['artist'][0];
            }

            if (isset($tags['id3v2']['title'][0])) {
                $title = (string) $tags['id3v2']['title'][0];
            } elseif (isset($tags['id3v1']['title'][0])) {
                $title = (string) $tags['id3v1']['title'][0];
            }

            if (isset($tags['id3v2']['album'][0])) {
                $album = (string) $tags['id3v2']['album'][0];
            } elseif (isset($tags['id3v1']['album'][0])) {
                $album = (string) $tags['id3v1']['album'][0];
            }
        }

        $additionalMetadata = [];
        if (isset($fileInfo['tags'])) {
            $additionalMetadata['tags'] = $fileInfo['tags'];
        }

        return new MediaMeta(
            $fileInfoObj->getFilename(),
            $mimeType,
            $fileInfoObj->getSize(),
            $width,
            $height,
            $duration,
            $artist,
            $title,
            $album,
            $additionalMetadata
        );
    }

    #[\Override]
    public function extractDate(string $filePath): MediaDate
    {
        $filePathObj = new \SortingPhotosByDate\Domain\ValueObjects\FilePath($filePath);
        if (!$this->filesystem->exists($filePathObj)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        /** @var array<string, mixed> $fileInfo */
        $fileInfo = $this->metadataAnalyzer->analyze($filePath);

        // Priority: ID3 TDRC > ID3 TYER > mtime
        if (isset($fileInfo['tags']['id3v2']['TDRC'][0])) {
            $dateString = (string) $fileInfo['tags']['id3v2']['TDRC'][0];
            $date = $this->parseId3Date($dateString);
            if ($date instanceof Carbon) {
                return new MediaDate($date);
            }
        }

        if (isset($fileInfo['tags']['id3v2']['TYER'][0])) {
            $year = (string) $fileInfo['tags']['id3v2']['TYER'][0];
            if (is_numeric($year) && $year >= 1900 && $year <= 2100) {
                return new MediaDate(Carbon::createFromDate((int) $year, 1, 1));
            }
        }

        // Fallback to file modification time using FilesystemPort
        $mtime = $this->filesystem->getModificationTime($filePathObj);

        return MediaDate::fromTimestamp($mtime);
    }

    private function parseId3Date(string $dateString): ?Carbon
    {
        try {
            // Try various ID3 date formats
            $formats = ['Y-m-d', 'Y-m-d H:i:s', 'Y'];
            foreach ($formats as $format) {
                return Carbon::createFromFormat($format, $dateString);
            }

            // Try parsing as ISO 8601
            return Carbon::parse($dateString);
        } catch (\Exception) {
            return null;
        }
    }
}
