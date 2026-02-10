<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(
    name: 'google-photos:reset',
    description: 'Reset all upload data and start from scratch (preserves authentication tokens)'
)]
final class GooglePhotosResetCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->setHelp(
                <<<'HELP'
The <info>%command.name%</info> command resets all upload-related data and starts from scratch.

This command will:
  - Delete all upload jobs
  - Delete all upload batches
  - Reset quota status
  - Clear all distributed locks

This command will NOT delete:
  - Authentication tokens (you won't need to re-authorize)

<comment>WARNING: This action cannot be undone!</comment>

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Show statistics before reset
        $stats = $this->getStatistics();
        $io->title('Reset Google Photos Upload Data');

        $io->section('Current Statistics');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total Jobs', $stats['total_jobs']],
                ['Total Batches', $stats['total_batches']],
                ['Active Locks', $stats['active_locks']],
            ]
        );

        // Confirmation
        if (!$input->getOption('force')) {
            $io->warning([
                'This will permanently delete all upload data:',
                '  - All upload jobs',
                '  - All upload batches',
                '  - Quota status',
                '  - Distributed locks',
                '',
                'Authentication tokens will be preserved.',
                '',
                '<comment>This action cannot be undone!</comment>',
            ]);

            $question = new ConfirmationQuestion(
                'Are you sure you want to reset all data? [y/N]: ',
                false
            );

            if (!$io->askQuestion($question)) {
                $io->info('Reset cancelled');

                return Command::SUCCESS;
            }
        }

        // Perform reset
        $io->section('Resetting Data');

        try {
            $this->connection->beginTransaction();

            // Delete upload jobs
            $io->text('Deleting upload jobs...');
            $jobsDeleted = $this->connection->executeStatement(
                'DELETE FROM google_photos_upload_jobs'
            );
            $io->text(\sprintf('  Deleted %d jobs', $jobsDeleted));

            // Delete upload batches
            $io->text('Deleting upload batches...');
            $batchesDeleted = $this->connection->executeStatement(
                'DELETE FROM google_photos_upload_batches'
            );
            $io->text(\sprintf('  Deleted %d batches', $batchesDeleted));

            // Reset quota status (delete all records)
            $io->text('Resetting quota status...');
            $quotaDeleted = $this->connection->executeStatement(
                'DELETE FROM google_photos_quota_status'
            );
            $io->text(\sprintf('  Deleted %d quota records', $quotaDeleted));

            // Clear distributed locks
            $io->text('Clearing distributed locks...');
            $locksDeleted = $this->connection->executeStatement(
                'DELETE FROM distributed_locks'
            );
            $io->text(\sprintf('  Deleted %d locks', $locksDeleted));

            $this->connection->commit();

            $io->success([
                'All data has been reset successfully!',
                '',
                \sprintf('Deleted: %d jobs, %d batches, %d quota records, %d locks', $jobsDeleted, $batchesDeleted, $quotaDeleted, $locksDeleted),
                '',
                'You can now start fresh by scanning files:',
                '  <info>php bin/console google-photos:upload --scan-only</info>',
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->connection->rollBack();

            $io->error([
                'Failed to reset data:',
                $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * @return array{total_jobs: int, total_batches: int, active_locks: int}
     */
    private function getStatistics(): array
    {
        $totalJobs = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM google_photos_upload_jobs'
        );

        $totalBatches = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM google_photos_upload_batches'
        );

        $activeLocks = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM distributed_locks'
        );

        return [
            'total_jobs' => (int) ($totalJobs ?? 0),
            'total_batches' => (int) ($totalBatches ?? 0),
            'active_locks' => (int) ($activeLocks ?? 0),
        ];
    }
}
