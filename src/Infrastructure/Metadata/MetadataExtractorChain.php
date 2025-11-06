<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Metadata;

use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;
use SortingPhotosByDate\Ports\MimeTypeDetectorInterface;
use SortingPhotosByDate\Ports\MetadataExtractorPort;

/**
 * Chain of Responsibility pattern for metadata extractors.
 * Tries each extractor in order until one supports the MIME type.
 */
final readonly class MetadataExtractorChain implements MetadataExtractorPort
{
    /**
     * @param iterable<MetadataExtractorPort> $extractors
     */
    public function __construct(
        private iterable $extractors,
        private MimeTypeDetectorInterface $mimeTypeDetector,
    ) {
    }

    #[\Override]
    public function extract(string $filePath): MediaMeta
    {
        // Detect MIME type first
        $mimeType = $this->mimeTypeDetector->detectMimeType($filePath);

        // Find the best extractor for this MIME type
        $bestExtractor = $this->findBestExtractor($mimeType);

        return $bestExtractor->extract($filePath);
    }

    #[\Override]
    public function extractDate(string $filePath): MediaDate
    {
        // Detect MIME type first
        $mimeType = $this->mimeTypeDetector->detectMimeType($filePath);

        // Find the best extractor for this MIME type
        $bestExtractor = $this->findBestExtractor($mimeType);

        return $bestExtractor->extractDate($filePath);
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($mimeType)) {
                return true;
            }
        }

        return false;
    }

    private function findBestExtractor(string $mimeType): MetadataExtractorPort
    {
        // Try to find a specific extractor first (ExifAdapter, GetId3Adapter)
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($mimeType)) {
                return $extractor;
            }
        }

        // Fallback to GenericAdapter (supports all)
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($mimeType)) {
                return $extractor;
            }
        }

        throw new \RuntimeException("No extractor found for MIME type: {$mimeType}");
    }
}

