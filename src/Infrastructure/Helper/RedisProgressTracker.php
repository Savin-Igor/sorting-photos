<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Helper;

use SortingPhotosByDate\Infrastructure\Statistics\RedisStatisticsService;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Progress tracker that reads statistics from Redis in real-time.
 * Updates progress bar based on Redis counters for parallel processing.
 */
final class RedisProgressTracker
{
    private ProgressBar $progressBar;
    private int $totalFiles = 0;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly RedisStatisticsService $statistics,
        private readonly bool $verbose = false,
    ) {
    }

    public function initialize(int $totalFiles): void
    {
        $this->totalFiles = $totalFiles;
        $this->progressBar = new ProgressBar($this->output, $totalFiles);
        $this->progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% | %message%');
        $this->progressBar->setMessage('Starting...');
        $this->progressBar->start();
    }

    /**
     * Update progress bar from Redis statistics.
     * Call this periodically to show real-time progress.
     */
    public function update(): void
    {
        $stats = $this->statistics->getStats();
        $processed = $stats['processed'];
        $skipped = $stats['skipped'];
        $errors = $stats['errors'];
        $current = $processed + $skipped + $errors;

        // Update progress bar
        $this->progressBar->setProgress($current);

        // Update message with current stats
        $message = \sprintf(
            'Processed: %d, Skipped: %d, Errors: %d',
            $processed,
            $skipped,
            $errors
        );
        $this->progressBar->setMessage($message);
    }

    public function finish(): void
    {
        $this->progressBar->setMessage('Completed');
        $this->progressBar->finish();
        $this->output->writeln('');
    }

    /**
     * @return array{total: int, processed: int, skipped: int, duplicates: int, already_processed: int, errors: int}
     */
    public function getStats(): array
    {
        $stats = $this->statistics->getStats();

        return [
            'total' => $this->totalFiles,
            'processed' => $stats['processed'],
            'skipped' => $stats['skipped'],
            'duplicates' => $stats['duplicates'],
            'already_processed' => $stats['already_processed'],
            'errors' => $stats['errors'],
        ];
    }

    public function isVerbose(): bool
    {
        return $this->verbose;
    }
}
