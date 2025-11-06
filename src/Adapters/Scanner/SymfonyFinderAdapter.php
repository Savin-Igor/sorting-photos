<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Scanner;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\ScannerPort;
use Symfony\Component\Finder\Finder;

final class SymfonyFinderAdapter implements ScannerPort
{
    public function __construct(
        private readonly Finder $finder,
    ) {
    }

    #[\Override]
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
}
