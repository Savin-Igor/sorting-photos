<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports;

/**
 * Port for audio/video metadata analysis.
 * Abstracts away vendor library (getID3).
 */
interface AudioVideoMetadataAnalyzerInterface
{
    /**
     * Analyze audio/video file and extract metadata.
     *
     * @param string $filePath Path to the file
     *
     * @return array<string, mixed> Analysis results
     *
     * @throws \RuntimeException If analysis fails
     */
    public function analyze(string $filePath): array;
}
