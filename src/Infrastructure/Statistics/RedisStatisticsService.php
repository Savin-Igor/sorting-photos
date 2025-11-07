<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Statistics;

use Predis\ClientInterface;

/**
 * Service for storing and retrieving processing statistics in Redis.
 * Provides atomic counters for parallel processing.
 */
final readonly class RedisStatisticsService
{
    private const string KEY_PREFIX = 'sorting_photos:stats:';
    private const string KEY_TOTAL = self::KEY_PREFIX.'total';
    private const string KEY_PROCESSED = self::KEY_PREFIX.'processed';
    private const string KEY_SKIPPED = self::KEY_PREFIX.'skipped';
    private const string KEY_DUPLICATES = self::KEY_PREFIX.'duplicates';
    private const string KEY_ALREADY_PROCESSED = self::KEY_PREFIX.'already_processed';
    private const string KEY_ERRORS = self::KEY_PREFIX.'errors';

    public function __construct(
        private ClientInterface $redis,
    ) {
    }

    /**
     * Initialize statistics counters (reset to zero).
     */
    public function reset(): void
    {
        $this->redis->set(self::KEY_TOTAL, '0');
        $this->redis->set(self::KEY_PROCESSED, '0');
        $this->redis->set(self::KEY_SKIPPED, '0');
        $this->redis->set(self::KEY_DUPLICATES, '0');
        $this->redis->set(self::KEY_ALREADY_PROCESSED, '0');
        $this->redis->set(self::KEY_ERRORS, '0');
    }

    /**
     * Set total number of files to process.
     */
    public function setTotal(int $total): void
    {
        $this->redis->set(self::KEY_TOTAL, (string) $total);
    }

    /**
     * Increment processed files counter (atomic).
     */
    public function incrementProcessed(): int
    {
        return (int) $this->redis->incr(self::KEY_PROCESSED);
    }

    /**
     * Increment skipped files counter (atomic).
     */
    public function incrementSkipped(): int
    {
        return (int) $this->redis->incr(self::KEY_SKIPPED);
    }

    /**
     * Increment duplicate files counter (atomic).
     */
    public function incrementDuplicates(): int
    {
        return (int) $this->redis->incr(self::KEY_DUPLICATES);
    }

    /**
     * Increment already processed files counter (atomic).
     */
    public function incrementAlreadyProcessed(): int
    {
        return (int) $this->redis->incr(self::KEY_ALREADY_PROCESSED);
    }

    /**
     * Increment error files counter (atomic).
     */
    public function incrementErrors(): int
    {
        return (int) $this->redis->incr(self::KEY_ERRORS);
    }

    /**
     * Get all statistics.
     * Optimized to use MGET for single round-trip to Redis.
     *
     * @return array{total: int, processed: int, skipped: int, duplicates: int, already_processed: int, errors: int}
     */
    public function getStats(): array
    {
        // Use MGET to fetch all values in a single round-trip
        $values = $this->redis->mget([
            self::KEY_TOTAL,
            self::KEY_PROCESSED,
            self::KEY_SKIPPED,
            self::KEY_DUPLICATES,
            self::KEY_ALREADY_PROCESSED,
            self::KEY_ERRORS,
        ]);

        // Ensure we have 6 values, defaulting to null if missing
        /** @var array<int, string|null> $values */
        return [
            'total' => (int) ($values[0] ?? 0),
            'processed' => (int) ($values[1] ?? 0),
            'skipped' => (int) ($values[2] ?? 0),
            'duplicates' => (int) ($values[3] ?? 0),
            'already_processed' => (int) ($values[4] ?? 0),
            'errors' => (int) ($values[5] ?? 0),
        ];
    }

    /**
     * Clear all statistics keys.
     */
    public function clear(): void
    {
        $keys = $this->redis->keys(self::KEY_PREFIX.'*');
        if (!empty($keys)) {
            /** @var array<string> $keys */
            $this->redis->del($keys);
        }
    }
}
