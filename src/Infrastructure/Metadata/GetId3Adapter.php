<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Metadata;

use SortingPhotosByDate\Ports\AudioVideoMetadataAnalyzerInterface;

/**
 * Adapter wrapper for getID3 library.
 * Implements AudioVideoMetadataAnalyzerInterface using vendor library.
 */
final readonly class GetId3Adapter implements AudioVideoMetadataAnalyzerInterface
{
    public function __construct(private \JamesHeinrich\GetID3\GetID3 $getId3 = new \JamesHeinrich\GetID3\GetID3())
    {
    }

    #[\Override]
    public function analyze(string $filePath): array
    {
        /** @var array<string, mixed> $fileInfo */
        $fileInfo = $this->getId3->analyze($filePath);

        return $fileInfo;
    }
}
