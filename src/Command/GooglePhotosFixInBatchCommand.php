<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use Doctrine\DBAL\Connection;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchState;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatchId;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJobId;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadState;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadBatchRepositoryPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google-photos:fix-in-batch',
    description: 'Fix jobs stuck in IN_BATCH state that belong to completed batches'
)]
final class GooglePhotosFixInBatchCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UploadJobRepositoryPort $jobRepository,
        private readonly UploadBatchRepositoryPort $batchRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be fixed without making changes'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        // First, show statistics about IN_BATCH jobs
        $stats = $this->connection->fetchAllAssociative(
            'SELECT 
                COALESCE(b.state, "NULL") as batch_state,
                COUNT(*) as count
             FROM google_photos_upload_jobs j
             LEFT JOIN google_photos_upload_batches b ON j.batch_id = b.id
             WHERE j.state = ?
             GROUP BY b.state',
            [UploadState::IN_BATCH->value]
        );

        if ([] !== $stats) {
            $io->section('IN_BATCH Jobs Statistics');
            $io->table(
                ['Batch State', 'Job Count'],
                \array_map(
                    fn (array $row): array => [$row['batch_state'], $row['count']],
                    $stats
                )
            );
        }

        // Find jobs in IN_BATCH state that belong to completed batches
        $completedRows = $this->connection->fetchAllAssociative(
            'SELECT j.id, j.batch_id, j.file_path, b.state as batch_state
             FROM google_photos_upload_jobs j
             INNER JOIN google_photos_upload_batches b ON j.batch_id = b.id
             WHERE j.state = ?
             AND b.state = ?',
            [UploadState::IN_BATCH->value, BatchState::COMPLETED->value]
        );

        // Also find jobs in UPLOADED state that belong to completed batches
        $uploadedCompletedRows = $this->connection->fetchAllAssociative(
            'SELECT j.id, j.batch_id, j.file_path, b.state as batch_state
             FROM google_photos_upload_jobs j
             INNER JOIN google_photos_upload_batches b ON j.batch_id = b.id
             WHERE j.state = ?
             AND b.state = ?',
            [UploadState::UPLOADED->value, BatchState::COMPLETED->value]
        );

        // Also find jobs in UPLOADED state that belong to failed batches
        $uploadedFailedRows = $this->connection->fetchAllAssociative(
            'SELECT j.id, j.batch_id, j.file_path, b.state as batch_state, b.error_message
             FROM google_photos_upload_jobs j
             INNER JOIN google_photos_upload_batches b ON j.batch_id = b.id
             WHERE j.state = ?
             AND b.state = ?',
            [UploadState::UPLOADED->value, BatchState::FAILED->value]
        );

        // Find jobs in IN_BATCH state that belong to failed batches
        $failedRows = $this->connection->fetchAllAssociative(
            'SELECT j.id, j.batch_id, j.file_path, b.state as batch_state, b.error_message
             FROM google_photos_upload_jobs j
             INNER JOIN google_photos_upload_batches b ON j.batch_id = b.id
             WHERE j.state = ?
             AND b.state = ?',
            [UploadState::IN_BATCH->value, BatchState::FAILED->value]
        );

        $rows = \array_merge($completedRows, $failedRows, $uploadedCompletedRows, $uploadedFailedRows);

        if ([] === $rows) {
            $io->success('No jobs found that need fixing.');

            return Command::SUCCESS;
        }

        $io->writeln(\sprintf(
            'Found <info>%d</info> jobs that need fixing:',
            \count($rows)
        ));
        $io->listing([
            \sprintf('%d IN_BATCH jobs from completed batches', \count($completedRows)),
            \sprintf('%d IN_BATCH jobs from failed batches', \count($failedRows)),
            \sprintf('%d UPLOADED jobs from completed batches', \count($uploadedCompletedRows)),
            \sprintf('%d UPLOADED jobs from failed batches', \count($uploadedFailedRows)),
        ]);

        if ($dryRun) {
            $io->note('DRY RUN mode - no changes will be made');
            $io->table(
                ['Job ID', 'Batch ID', 'File Path'],
                \array_map(
                    fn (array $row): array => [
                        $row['id'],
                        $row['batch_id'],
                        $row['file_path'],
                    ],
                    \array_slice($rows, 0, 20)
                )
            );
            if (\count($rows) > 20) {
                $io->writeln(\sprintf('... and %d more', \count($rows) - 20));
            }

            return Command::SUCCESS;
        }

        $fixed = 0;
        $errors = 0;

        foreach ($rows as $row) {
            try {
                $jobIdStr = \is_string($row['id'] ?? null) ? $row['id'] : '';
                if ('' === $jobIdStr) {
                    $io->warning('Job ID is missing or invalid');
                    ++$errors;
                    continue;
                }

                $jobId = UploadJobId::fromString($jobIdStr);
                $job = $this->jobRepository->findById($jobId);

                if (!$job instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob) {
                    $io->warning(\sprintf('Job not found: %s', $jobIdStr));
                    ++$errors;
                    continue;
                }

                // Verify batch is completed
                $batchIdStr = \is_string($row['batch_id'] ?? null) ? $row['batch_id'] : '';
                if ('' === $batchIdStr) {
                    $io->warning(\sprintf('Batch ID is missing for job: %s', $jobIdStr));
                    ++$errors;
                    continue;
                }

                $batchId = UploadBatchId::fromString($batchIdStr);
                $batch = $this->batchRepository->findById($batchId);

                if (!$batch instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch) {
                    $io->warning(\sprintf('Batch not found: %s', $batchIdStr));
                    ++$errors;
                    continue;
                }

                // Check if job is actually in IN_BATCH or UPLOADED state
                $currentState = $job->getState();
                if (UploadState::IN_BATCH !== $currentState && UploadState::UPLOADED !== $currentState) {
                    $io->warning(\sprintf('Job is not in IN_BATCH or UPLOADED state: %s (state: %s)', $jobIdStr, $currentState->value));
                    continue;
                }

                if (BatchState::COMPLETED === $batch->getState()) {
                    // For UPLOADED jobs, first transition to IN_BATCH, then to COMPLETED
                    if (UploadState::UPLOADED === $currentState) {
                        // Check if this job was successfully processed in the batch
                        // If batch is completed, assume all items were processed successfully
                        $job = $job->assignToBatch($batch->getId());
                        $this->jobRepository->save($job);
                        // Now mark as completed
                        $completedJob = $job->markCompleted();
                        $this->jobRepository->save($completedJob);
                    } else {
                        // Already in IN_BATCH, just mark as completed
                        $completedJob = $job->markCompleted();
                        $this->jobRepository->save($completedJob);
                    }
                    ++$fixed;
                } elseif (BatchState::FAILED === $batch->getState()) {
                    // For failed batches, mark job as FAILED so it can be retried later
                    // The job will need to be manually reset to PENDING or handled by retry logic
                    $errorMessage = $batch->getErrorMessage() ?? 'Batch failed';
                    $failedJob = $job->markAsFailed(\sprintf('Batch failed: %s', $errorMessage));
                    $this->jobRepository->save($failedJob);
                    ++$fixed;
                } else {
                    $io->warning(\sprintf('Batch is in unexpected state: %s (state: %s)', $batchIdStr, $batch->getState()->value));
                    ++$errors;
                    continue;
                }
            } catch (\Exception $e) {
                $jobIdStr = \is_string($row['id'] ?? null) ? $row['id'] : 'unknown';
                $io->error(\sprintf('Error processing job %s: %s', $jobIdStr, $e->getMessage()));
                ++$errors;
            }
        }

        $io->success(\sprintf('Fixed <info>%d</info> jobs. Errors: <error>%d</error>', $fixed, $errors));

        return Command::SUCCESS;
    }
}
