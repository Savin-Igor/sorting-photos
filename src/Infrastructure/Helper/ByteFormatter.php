<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Helper;

/**
 * Helper class for formatting byte sizes into human-readable format.
 */
final readonly class ByteFormatter
{
    private const array UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];
    private const int UNIT_SIZE = 1024;
    private const int MAX_UNIT_INDEX = 4;
    private const int DECIMAL_PLACES = 2;

    /**
     * Formats the size in bytes into a human-readable string (KB, MB, GB, etc.).
     *
     * @param int $size Size in bytes
     *
     * @return string Formatted string (e.g., "1.5 MB")
     */
    public function format(int $size): string
    {
        if ($size < 0) {
            throw new \InvalidArgumentException('Size cannot be negative');
        }

        $unitIndex = 0;
        $formattedSize = (float) $size;

        while ($formattedSize >= self::UNIT_SIZE && $unitIndex < self::MAX_UNIT_INDEX) {
            $formattedSize /= self::UNIT_SIZE;
            ++$unitIndex;
        }

        return round($formattedSize, self::DECIMAL_PLACES).' '.self::UNITS[$unitIndex];
    }
}
