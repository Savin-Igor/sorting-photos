<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Scanner;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\ScannerPort;
use Symfony\Component\Finder\Finder;

final readonly class SymfonyFinderAdapter implements ScannerPort
{
    public function scan(string $directory): iterable
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Directory does not exist: {$directory}");
        }

        // Create a fresh Finder instance for each scan to avoid state issues
        $files = Finder::create()
            ->files()
            ->in($directory)
            ->ignoreDotFiles(true)
            ->ignoreVCS(true);

        foreach ($files as $file) {
            yield new FilePath($file->getRealPath() ?: $file->getPathname());
        }
    }

    public function count(string $directory): int
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Directory does not exist: {$directory}");
        }

        // Create a fresh Finder instance for each count to avoid state issues
        // Finder is recursive by default, so this will count all files in subdirectories
        return Finder::create()
            ->files()
            ->in($directory)
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
            ->count();
    }
}
