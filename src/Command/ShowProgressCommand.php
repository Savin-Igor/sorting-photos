<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use SortingPhotosByDate\Infrastructure\Helper\RedisProgressTracker;
use SortingPhotosByDate\Infrastructure\Statistics\RedisStatisticsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'show:progress',
    description: 'Show real-time processing progress and statistics'
)]
final class ShowProgressCommand extends Command
{
    public function __construct(
        private readonly ?RedisStatisticsService $statistics = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setDescription('Show real-time processing progress and statistics from Redis')
            ->addOption(
                'interval',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Update interval in seconds',
                0.5
            )
            ->addOption(
                'timeout',
                't',
                InputOption::VALUE_OPTIONAL,
                'Maximum time to wait in seconds (0 = infinite)',
                0
            )
            ->addOption(
                'once',
                null,
                InputOption::VALUE_NONE,
                'Show statistics once and exit'
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check if Redis is available
        if (!$this->statistics instanceof RedisStatisticsService) {
            $io->error('Redis is not available. This command requires Redis for statistics tracking.');
            $io->note('To enable Redis:');
            $io->writeln('  1. Set REDIS_URL in .env file (e.g., REDIS_URL=tcp://redis:6379)');
            $io->writeln('  2. Or start Redis service locally');
            $io->writeln('  3. Or use Docker: make up');

            return Command::FAILURE;
        }

        $intervalOption = $input->getOption('interval');
        $timeoutOption = $input->getOption('timeout');
        $interval = \is_numeric($intervalOption) ? (float) $intervalOption : 0.5;
        $timeout = \is_numeric($timeoutOption) ? (int) $timeoutOption : 0;
        $once = $input->getOption('once');

        // Get initial statistics
        $stats = $this->statistics->getStats();
        $totalFiles = $stats['total'];

        if (0 === $totalFiles) {
            $io->warning('No processing in progress. Total files: 0');
            $io->note('Start processing with: make run');

            return Command::SUCCESS;
        }

        // Create progress tracker
        $progressTracker = new RedisProgressTracker(
            $output,
            $this->statistics,
            $output->isVerbose()
        );
        $progressTracker->initialize($totalFiles);

        if ($once) {
            // Show statistics once and exit
            $progressTracker->update();
            $finalStats = $progressTracker->getStats();
            $progressTracker->finish();

            $io->section('Current Statistics');
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total files', (string) $finalStats['total']],
                    ['Processed', (string) $finalStats['processed']],
                    ['Skipped', (string) $finalStats['skipped']],
                    ['  - Duplicates', (string) $finalStats['duplicates']],
                    ['  - Already processed', (string) $finalStats['already_processed']],
                    ['Errors', (string) $finalStats['errors']],
                    ['Remaining', (string) max(0, $finalStats['total'] - $finalStats['processed'] - $finalStats['skipped'] - $finalStats['errors'])],
                ]
            );

            return Command::SUCCESS;
        }

        // Continuous monitoring mode
        $io->note(\sprintf(
            'Monitoring progress (interval: %.1fs, timeout: %s)...',
            $interval,
            $timeout > 0 ? $timeout.'s' : 'infinite'
        ));
        $io->writeln('Press Ctrl+C to stop');
        $io->writeln('');

        $startTime = microtime(true);
        $lastUpdate = 0;
        $stalledCount = 0;
        $lastProcessed = 0;

        while (true) {
            $elapsed = microtime(true) - $startTime;

            // Check timeout
            if ($timeout > 0 && $elapsed >= $timeout) {
                $io->writeln('');
                $io->note(\sprintf('Timeout reached (%ds)', $timeout));

                break;
            }

            // Update progress bar at specified interval
            if (($elapsed - $lastUpdate) >= $interval) {
                $stats = $this->statistics->getStats();
                $current = $stats['processed'] + $stats['skipped'] + $stats['errors'];

                $progressTracker->update();
                $lastUpdate = $elapsed;

                // Check if processing is stalled
                if (0 < $current && $current === $lastProcessed) {
                    ++$stalledCount;
                    // If stalled for more than 5 seconds, show warning
                    if ($stalledCount > (int) (5 / $interval)) {
                        $io->writeln('');
                        $io->warning('Processing seems stalled. Make sure workers are running:');
                        $io->writeln('  <comment>make consume-workers WORKERS=8</comment>');
                        $stalledCount = 0; // Reset counter
                    }
                } else {
                    $stalledCount = 0;
                }

                $lastProcessed = $current;

                // Check if all files are processed
                if ($totalFiles > 0 && $current >= $totalFiles) {
                    $progressTracker->finish();
                    $io->writeln('');
                    $io->success('All files processed!');

                    // Show final statistics
                    $finalStats = $progressTracker->getStats();
                    $io->table(
                        ['Metric', 'Value'],
                        [
                            ['Total files', (string) $finalStats['total']],
                            ['Processed', (string) $finalStats['processed']],
                            ['Skipped', (string) $finalStats['skipped']],
                            ['  - Duplicates', (string) $finalStats['duplicates']],
                            ['  - Already processed', (string) $finalStats['already_processed']],
                            ['Errors', (string) $finalStats['errors']],
                        ]
                    );

                    return Command::SUCCESS;
                }
            }

            usleep((int) ($interval * 1000000)); // Convert seconds to microseconds
        }

        // Show final statistics before exit
        $progressTracker->update();
        $finalStats = $progressTracker->getStats();
        $progressTracker->finish();

        $io->writeln('');
        $io->section('Current Statistics');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total files', (string) $finalStats['total']],
                ['Processed', (string) $finalStats['processed']],
                ['Skipped', (string) $finalStats['skipped']],
                ['  - Duplicates', (string) $finalStats['duplicates']],
                ['  - Already processed', (string) $finalStats['already_processed']],
                ['Errors', (string) $finalStats['errors']],
                ['Remaining', (string) max(0, $finalStats['total'] - $finalStats['processed'] - $finalStats['skipped'] - $finalStats['errors'])],
            ]
        );

        return Command::SUCCESS;
    }
}
