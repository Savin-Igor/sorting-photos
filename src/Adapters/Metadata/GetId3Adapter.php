<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Metadata;

use Carbon\Carbon;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Infrastructure\Metadata\FilenameDateExtractor;
use SortingPhotosByDate\Ports\AudioVideoMetadataAnalyzerInterface;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\MimeTypeDetectorInterface;
use SortingPhotosByDate\Ports\MetadataExtractorPort;

final readonly class GetId3Adapter implements MetadataExtractorPort
{
    public function __construct(
        private MimeTypeDetectorInterface $mimeTypeDetector,
        private AudioVideoMetadataAnalyzerInterface $metadataAnalyzer,
        private FilesystemPort $filesystem,
        private FilenameDateExtractor $filenameDateExtractor,
        private LoggerPort $logger,
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
        $fileSize = $this->filesystem->getSize($filePathObj);

        $width = null;
        $height = null;
        if (isset($fileInfo['video']) && is_array($fileInfo['video']) && isset($fileInfo['video']['resolution_x'], $fileInfo['video']['resolution_y'])) {
            $video = $fileInfo['video'];
            $resolutionX = $video['resolution_x'];
            $resolutionY = $video['resolution_y'];
            if (is_numeric($resolutionX) && is_numeric($resolutionY)) {
                $width = (int) $resolutionX;
                $height = (int) $resolutionY;
            }
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
            if (isset($tags['id3v2']) && is_array($tags['id3v2']) && isset($tags['id3v2']['artist']) && is_array($tags['id3v2']['artist']) && isset($tags['id3v2']['artist'][0])) {
                $artist = (string) $tags['id3v2']['artist'][0];
            } elseif (isset($tags['id3v1']) && is_array($tags['id3v1']) && isset($tags['id3v1']['artist']) && is_array($tags['id3v1']['artist']) && isset($tags['id3v1']['artist'][0])) {
                $artist = (string) $tags['id3v1']['artist'][0];
            }

            if (isset($tags['id3v2']) && is_array($tags['id3v2']) && isset($tags['id3v2']['title']) && is_array($tags['id3v2']['title']) && isset($tags['id3v2']['title'][0])) {
                $title = (string) $tags['id3v2']['title'][0];
            } elseif (isset($tags['id3v1']) && is_array($tags['id3v1']) && isset($tags['id3v1']['title']) && is_array($tags['id3v1']['title']) && isset($tags['id3v1']['title'][0])) {
                $title = (string) $tags['id3v1']['title'][0];
            }

            if (isset($tags['id3v2']) && is_array($tags['id3v2']) && isset($tags['id3v2']['album']) && is_array($tags['id3v2']['album']) && isset($tags['id3v2']['album'][0])) {
                $album = (string) $tags['id3v2']['album'][0];
            } elseif (isset($tags['id3v1']) && is_array($tags['id3v1']) && isset($tags['id3v1']['album']) && is_array($tags['id3v1']['album']) && isset($tags['id3v1']['album'][0])) {
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
            $fileSize,
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

        // Priority 1: Extract from filename (most reliable - filename changes less often than metadata)
        $filenameDate = $this->filenameDateExtractor->extract($filePath);
        if ($filenameDate instanceof Carbon) {
            // Determine if this is a video file for logging
            $mimeType = $this->mimeTypeDetector->detectMimeType($filePath);
            $isVideo = str_starts_with($mimeType, 'video/');

            $this->logger->info('Date extracted from filename', [
                'file_path' => $filePath,
                'is_video' => $isVideo,
                'extracted_date' => $filenameDate->format('Y-m-d H:i:s'),
                'source' => 'filename',
            ]);

            return new MediaDate($filenameDate);
        }

        /** @var array<string, mixed> $fileInfo */
        $fileInfo = $this->metadataAnalyzer->analyze($filePath);

        // Priority 2: ID3 TDRC > ID3 TYER
        if (isset($fileInfo['tags']) && is_array($fileInfo['tags']) && isset($fileInfo['tags']['id3v2']) && is_array($fileInfo['tags']['id3v2']) && isset($fileInfo['tags']['id3v2']['TDRC']) && is_array($fileInfo['tags']['id3v2']['TDRC']) && isset($fileInfo['tags']['id3v2']['TDRC'][0])) {
            $dateString = (string) $fileInfo['tags']['id3v2']['TDRC'][0];
            $date = $this->parseId3Date($dateString);
            if ($date instanceof Carbon && $this->isValidDate($date)) {
                return new MediaDate($date);
            }
        }

        if (isset($fileInfo['tags']) && is_array($fileInfo['tags']) && isset($fileInfo['tags']['id3v2']) && is_array($fileInfo['tags']['id3v2']) && isset($fileInfo['tags']['id3v2']['TYER']) && is_array($fileInfo['tags']['id3v2']['TYER']) && isset($fileInfo['tags']['id3v2']['TYER'][0])) {
            $year = (string) $fileInfo['tags']['id3v2']['TYER'][0];
            if (is_numeric($year) && $year >= 1900 && $year <= 2100) {
                return new MediaDate(Carbon::createFromDate((int) $year, 1, 1));
            }
        }

        // Priority 3: Fallback to file modification time
        $mtime = $this->filesystem->getModificationTime($filePathObj);

        return MediaDate::fromTimestamp($mtime);
    }

    private function isValidDate(Carbon $date): bool
    {
        $year = $date->year;

        // Accept dates between 1900 and 2100
        return $year >= 1900 && $year <= 2100;
    }

    private function parseId3Date(string $dateString): ?Carbon
    {
        // Try various ID3 date formats
        $formats = ['Y-m-d', 'Y-m-d H:i:s', 'Y'];
        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $dateString);
            } catch (\Exception) {
                continue;
            }
        }

        // Try parsing as ISO 8601
        try {
            return Carbon::parse($dateString);
        } catch (\Exception) {
            return null;
        }
    }
}
