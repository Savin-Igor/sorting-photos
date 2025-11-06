<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Helper;

use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\FilesystemPort;

/**
 * Helper class for calculating directory sizes.
 */
final readonly class DirectorySizeCalculator
{
    public function __construct(
        private FilesystemPort $filesystem,
    ) {
    }

    /**
     * Recursively calculates the total size of all files in a directory.
     *
     * @param string $directory Directory path
     *
     * @return int Total size in bytes
     */
    public function calculate(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isFile()) {
                try {
                    $realPath = $file->getRealPath();
                    $pathname = $file->getPathname();
                    if (false === $realPath || '' === $realPath) {
                        $realPath = $pathname;
                    }
                    if ('' === $realPath) {
                        continue;
                    }
                    $filePath = new FilePath($realPath);
                    $size += $this->filesystem->getSize($filePath);
                } catch (\Exception) {
                    // Skip files that cannot be read
                    continue;
                }
            }
        }

        return $size;
    }
}
