<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Helper;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Helper class for tracking and displaying progress.
 */
final class ProgressTracker
{
    private ProgressBar $progressBar;
    private int $totalFiles = 0;
    private int $processedFiles = 0;
    private int $duplicateFiles = 0;
    private int $errorFiles = 0;
    private int $skippedFiles = 0;

    public function __construct(
        private readonly OutputInterface $output,
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

    public function advance(string $message = ''): void
    {
        ++$this->processedFiles;
        if ('' !== $message) {
            $this->progressBar->setMessage($message);
        }
        $this->progressBar->advance();
    }

    public function incrementDuplicate(): void
    {
        ++$this->duplicateFiles;
    }

    public function incrementError(): void
    {
        ++$this->errorFiles;
    }

    public function incrementSkipped(): void
    {
        ++$this->skippedFiles;
    }

    public function finish(): void
    {
        $this->progressBar->setMessage('Completed');
        $this->progressBar->finish();
        $this->output->writeln('');
    }

    /**
     * @return array{total: int, processed: int, duplicates: int, errors: int, skipped: int}
     */
    public function getStats(): array
    {
        return [
            'total' => $this->totalFiles,
            'processed' => $this->processedFiles,
            'duplicates' => $this->duplicateFiles,
            'errors' => $this->errorFiles,
            'skipped' => $this->skippedFiles,
        ];
    }

    public function isVerbose(): bool
    {
        return $this->verbose;
    }
}
