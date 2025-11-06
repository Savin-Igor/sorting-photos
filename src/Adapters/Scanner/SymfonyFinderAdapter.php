<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Scanner;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\ScannerPort;
use Symfony\Component\Finder\Finder;

final readonly class SymfonyFinderAdapter implements ScannerPort
{
    public function __construct(
        private Finder $finder,
    ) {
    }

    public function scan(string $directory): iterable
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Directory does not exist: {$directory}");
        }

        $files = $this->finder
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

        return $this->finder
            ->files()
            ->in($directory)
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
            ->count();
    }
}
