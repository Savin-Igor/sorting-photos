<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports;

/**
 * Port for scanning files in a directory.
 */
interface ScannerPort
{
    /**
     * Count files in directory without loading them into memory.
     *
     * @param string $directory Directory to scan
     *
     * @return int Number of files
     */
    public function count(string $directory): int;
}
