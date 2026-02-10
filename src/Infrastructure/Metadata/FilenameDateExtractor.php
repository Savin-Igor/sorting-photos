<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Metadata;

use Carbon\Carbon;

/**
 * Extracts date from filename using various patterns.
 * Supports multiple date formats commonly found in filenames.
 */
final readonly class FilenameDateExtractor
{
    /**
     * Extract date from filename.
     * Tries multiple patterns in order of reliability.
     *
     * @param string $filePath Full file path or just filename
     *
     * @return Carbon|null Parsed date or null if not found
     */
    public function extract(string $filePath): ?Carbon
    {
        // Extract filename from path
        $filename = basename($filePath);

        // Try various patterns in order of specificity
        // More specific patterns (with date format) should come before generic timestamp patterns
        $patterns = [
            // Pattern 0: Russian format "от YYYY-MM-DD HH-MM-SS" (e.g., "от 2025-10-27 17-18-41")
            // Must be first to avoid matching as YYYY-MM-DD
            '/от\s+(\d{4})-(\d{2})-(\d{2})\s+(\d{2})-(\d{2})-(\d{2})/iu',
            // Pattern 1: YYYY-MM-DD_HH-MM-SS (e.g., 2014-08-02_04-01-34)
            '/(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})/',
            // Pattern 2: YYYY-MM-DD_HHMMSS (e.g., 2014-08-02_040134)
            '/(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})/',
            // Pattern 3: IMG_YYYYMMDD_HHMMSS (e.g., IMG_20200520_171804)
            '/IMG_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/i',
            // Pattern 4: YYYYMMDD_HHMMSS (e.g., 20200520_171804)
            '/\b(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})\b/',
            // Pattern 5: YYYYMMDDHHMMSS (14 digits, e.g., 20140802040134)
            '/\b(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\b/',
            // Pattern 6: YYYY-MM-DD (e.g., 2014-08-02, 2014-09-06)
            // Must not be followed by underscore and 6 digits (to avoid matching date-time patterns)
            // Match only if followed by non-digit, non-underscore character or end of string
            // Allow underscore followed by non-digit characters (e.g., _file.jpeg)
            // MOVED UP: More specific than timestamp patterns, should be checked before generic timestamps
            '/(\d{4})-(\d{2})-(\d{2})(?!_\d{6})(?![_]\d)/',
            // Pattern 7: YYYYMMDD (8 digits, e.g., 20140802) - but not part of longer number
            // Must be followed by non-digit character or end of string
            // MOVED UP: More specific than timestamp patterns
            '/(\d{4})(\d{2})(\d{2})(?!\d)/',
            // Pattern 8: DD.MM.YYYY (e.g., 03.11.2007)
            '/\b(\d{2})\.(\d{2})\.(\d{4})\b/',
            // Pattern 9: DD-MM-YYYY (e.g., 03-11-2007)
            '/\b(\d{2})-(\d{2})-(\d{4})\b/',
            // Pattern 10: YYYY/MM/DD (e.g., 2014/08/02)
            '/\b(\d{4})\/(\d{2})\/(\d{2})\b/',
            // Pattern 11: img + timestamp in milliseconds (e.g., img1405178243028)
            '/img(\d{13})/i',
            // Pattern 12: Any prefix + timestamp in milliseconds (13 digits, e.g., PhotoGrid_1404648109915)
            '/[^0-9](\d{13})(?!\d)/',
            // Pattern 13: Timestamp in seconds (10 digits, e.g., 1404648109)
            // MOVED DOWN: Less specific, should be checked after date format patterns
            // Also improved: only match if not part of a hex hash (hex has letters a-f)
            // Match 10 digits only if followed by non-hex character (not a-f, A-F) or end of string
            '/[^0-9a-fA-F](\d{10})(?![0-9a-fA-F])/',
        ];

        foreach ($patterns as $patternIndex => $pattern) {
            if (preg_match($pattern, $filename, $matches)) {
                $date = $this->parseMatch($patternIndex, $matches);
                if ($date instanceof Carbon && $this->isValidDate($date)) {
                    return $date;
                }
            }
        }

        return null;
    }

    /**
     * Parse matched pattern into Carbon date.
     *
     * @param int                $patternIndex Index of the pattern that matched
     * @param array<int, string> $matches      Matched groups
     *
     * @return Carbon|null Parsed date or null if invalid
     */
    private function parseMatch(int $patternIndex, array $matches): ?Carbon
    {
        try {
            return match ($patternIndex) {
                0 => $this->parseDateTimeSeparated($matches), // Russian format "от YYYY-MM-DD HH-MM-SS"
                1 => $this->parseDateTimeSeparated($matches), // YYYY-MM-DD_HH-MM-SS
                2 => $this->parseDateTimeCompact($matches),  // YYYY-MM-DD_HHMMSS
                3 => $this->parseImgFormat($matches),         // IMG_YYYYMMDD_HHMMSS
                4 => $this->parseDateTimeCompactNoDash($matches), // YYYYMMDD_HHMMSS
                5 => $this->parseDateTimeFull($matches),     // YYYYMMDDHHMMSS
                6 => $this->parseDateOnly($matches),         // YYYY-MM-DD
                7 => $this->parseDateCompact($matches),      // YYYYMMDD
                8 => $this->parseDateDDMMYYYY($matches),     // DD.MM.YYYY
                9 => $this->parseDateDDMMYYYYDash($matches), // DD-MM-YYYY
                10 => $this->parseDateYYYYMMDD($matches),    // YYYY/MM/DD
                11 => $this->parseTimestampMs($matches),      // img + 13-digit timestamp
                12 => $this->parseTimestampMs($matches),      // prefix + 13-digit timestamp
                13 => $this->parseTimestamp($matches),        // prefix + 10-digit timestamp
                default => null,
            };
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Parse YYYY-MM-DD_HH-MM-SS format.
     *
     * @param array<int, string> $matches Matched groups
     */
    private function parseDateTimeSeparated(array $matches): ?Carbon
    {
        if (count($matches) < 7) {
            return null;
        }

        return Carbon::create(
            (int) $matches[1], // year
            (int) $matches[2], // month
            (int) $matches[3], // day
            (int) $matches[4], // hour
            (int) $matches[5], // minute
            (int) $matches[6]  // second
        );
    }

    /**
     * Parse YYYY-MM-DD_HHMMSS format.
     *
     * @param array<int, string> $matches Matched groups
     */
    private function parseDateTimeCompact(array $matches): ?Carbon
    {
        if (count($matches) < 7) {
            return null;
        }

        // Pattern 2: YYYY-MM-DD_HHMMSS - groups are separated
        // $matches[1] = year, [2] = month, [3] = day, [4] = hour, [5] = minute, [6] = second
        return Carbon::create(
            (int) $matches[1], // year
            (int) $matches[2], // month
            (int) $matches[3], // day
            (int) $matches[4], // hour
            (int) $matches[5], // minute
            (int) $matches[6]  // second
        );
    }

    /**
     * Parse IMG_YYYYMMDD_HHMMSS format.
     *
     * @param array<int, string> $matches Matched groups
     */
    private function parseImgFormat(array $matches): ?Carbon
    {
        if (count($matches) < 7) {
            return null;
        }

        return Carbon::create(
            (int) $matches[1], // year
            (int) $matches[2], // month
            (int) $matches[3], // day
            (int) $matches[4], // hour
            (int) $matches[5], // minute
            (int) $matches[6]  // second
        );
    }

    /**
     * Parse YYYYMMDD_HHMMSS format.
     *
     * @param array<int, string> $matches Matched groups
     */
    private function parseDateTimeCompactNoDash(array $matches): ?Carbon
    {
        if (count($matches) < 7) {
            return null;
        }

        return Carbon::create(
            (int) $matches[1], // year
            (int) $matches[2], // month
            (int) $matches[3], // day
            (int) $matches[4], // hour
            (int) $matches[5], // minute
            (int) $matches[6]  // second
        );
    }

    /**
     * Parse YYYY-MM-DD format.
     *
     * @param array<int, string> $matches Matched groups
     */
    private function parseDateOnly(array $matches): ?Carbon
    {
        if (count($matches) < 4) {
            return null;
        }

        return Carbon::create(
            (int) $matches[1], // year
            (int) $matches[2], // month
            (int) $matches[3]  // day
        );
    }

    /**
     * Parse YYYYMMDD format.
     *
     * @param array<int, string> $matches Matched groups
     */
    private function parseDateCompact(array $matches): ?Carbon
    {
        if (count($matches) < 4) {
            return null;
        }

        return Carbon::create(
            (int) $matches[1], // year
            (int) $matches[2], // month
            (int) $matches[3]  // day
        );
    }

    /**
     * Parse timestamp in milliseconds (13 digits).
     *
     * @param array<int, string> $matches Matched groups
     */
    private function parseTimestampMs(array $matches): ?Carbon
    {
        if (!isset($matches[1]) || !is_numeric($matches[1])) {
            return null;
        }

        $timestampMs = (int) $matches[1];

        // Validate timestamp range (reasonable dates between 2000 and 2100)
        // Timestamp for 2000-01-01 00:00:00 UTC in milliseconds: 946684800000
        // Timestamp for 2100-01-01 00:00:00 UTC in milliseconds: 4102444800000
        if ($timestampMs < 946684800000 || $timestampMs > 4102444800000) {
            return null;
        }

        $timestamp = (int) ($timestampMs / 1000); // Convert milliseconds to seconds

        return Carbon::createFromTimestamp($timestamp);
    }

    /**
     * Parse timestamp in seconds (10 digits).
     *
     * @param array<int, string> $matches Matched groups
     */
    private function parseTimestamp(array $matches): ?Carbon
    {
        if (!isset($matches[1]) || !is_numeric($matches[1])) {
            return null;
        }

        $timestamp = (int) $matches[1];

        // Validate timestamp range (reasonable dates between 2000 and current year + 1)
        // Timestamp for 2000-01-01 00:00:00 UTC: 946684800
        // Timestamp for current year + 1: calculate dynamically to avoid future dates
        $currentYear = (int) date('Y');
        $maxTimestamp = mktime(23, 59, 59, 12, 31, $currentYear + 1);

        if ($timestamp < 946684800 || $timestamp > $maxTimestamp) {
            return null;
        }

        $date = Carbon::createFromTimestamp($timestamp);

        // Additional validation: reject dates too far in the future
        // This helps catch false positives from hex hashes or other numeric sequences
        if ($date->year > $currentYear + 1) {
            return null;
        }

        return $date;
    }

    /**
     * Parse YYYYMMDDHHMMSS format (14 digits).
     *
     * @param array<int, string> $matches Matched groups
     */
    private function parseDateTimeFull(array $matches): ?Carbon
    {
        if (count($matches) < 7) {
            return null;
        }

        return Carbon::create(
            (int) $matches[1], // year
            (int) $matches[2], // month
            (int) $matches[3], // day
            (int) $matches[4], // hour
            (int) $matches[5], // minute
            (int) $matches[6]  // second
        );
    }

    /**
     * Parse DD.MM.YYYY format.
     *
     * @param array<int, string> $matches Matched groups
     */
    private function parseDateDDMMYYYY(array $matches): ?Carbon
    {
        if (count($matches) < 4) {
            return null;
        }

        /** @var string $year */
        $year = $matches[3];
        /** @var string $month */
        $month = $matches[2];
        /** @var string $day */
        $day = $matches[1];

        return Carbon::create(
            (int) $year, // year
            (int) $month, // month
            (int) $day  // day
        );
    }

    /**
     * Parse DD-MM-YYYY format.
     *
     * @param array<int, string> $matches Matched groups
     */
    private function parseDateDDMMYYYYDash(array $matches): ?Carbon
    {
        if (count($matches) < 4) {
            return null;
        }

        /** @var string $year */
        $year = $matches[3];
        /** @var string $month */
        $month = $matches[2];
        /** @var string $day */
        $day = $matches[1];

        return Carbon::create(
            (int) $year, // year
            (int) $month, // month
            (int) $day  // day
        );
    }

    /**
     * Parse YYYY/MM/DD format.
     *
     * @param array<int, string> $matches Matched groups
     */
    private function parseDateYYYYMMDD(array $matches): ?Carbon
    {
        if (count($matches) < 4) {
            return null;
        }

        /** @var string $year */
        $year = $matches[1];
        /** @var string $month */
        $month = $matches[2];
        /** @var string $day */
        $day = $matches[3];

        return Carbon::create(
            (int) $year, // year
            (int) $month, // month
            (int) $day  // day
        );
    }

    /**
     * Validate that the date is reasonable (not too old or future).
     *
     * @param Carbon $date Date to validate
     *
     * @return bool True if date is valid
     */
    private function isValidDate(Carbon $date): bool
    {
        $year = $date->year;

        // Accept dates between 1900 and 2100
        return $year >= 1900 && $year <= 2100;
    }
}
