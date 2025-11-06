<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Port for scanning files in a directory.
 */
interface ScannerPort
{
    /**
     * Scan directory and return file paths.
     *
     * @param string $directory Directory to scan
     * @return iterable<FilePath> List of file paths
     */
    public function scan(string $directory): iterable;
}

