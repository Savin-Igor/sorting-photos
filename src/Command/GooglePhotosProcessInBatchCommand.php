<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use Doctrine\DBAL\Connection;
use SortingPhotosByDate\Application\Service\GooglePhotos\BatchProcessor;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchState;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatchId;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadState;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadBatchRepositoryPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google-photos:process-in-batch',
    description: 'Process batches that contain jobs in IN_BATCH state'
)]
final class GooglePhotosProcessInBatchCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UploadJobRepositoryPort $jobRepository,
        private readonly UploadBatchRepositoryPort $batchRepository,
        private readonly BatchProcessor $batchProcessor,
        private readonly LoggerPort $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be processed without making changes'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Limit number of batches to process',
                '0'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $limitOption = $input->getOption('limit');
        $limit = \is_string($limitOption) || \is_int($limitOption) ? (int) $limitOption : 0;

        // Show statistics
        $stats = $this->connection->fetchAllAssociative(
            'SELECT 
                COALESCE(b.state, "NULL") as batch_state,
                COUNT(DISTINCT j.batch_id) as batch_count,
                COUNT(*) as job_count
             FROM google_photos_upload_jobs j
             LEFT JOIN google_photos_upload_batches b ON j.batch_id = b.id
             WHERE j.state = ?
             GROUP BY b.state',
            [UploadState::IN_BATCH->value]
        );

        if ([] !== $stats) {
            $io->section('IN_BATCH Jobs Statistics');
            $io->table(
                ['Batch State', 'Batch Count', 'Job Count'],
                \array_map(
                    fn (array $row): array => [
                        $row['batch_state'],
                        $row['batch_count'],
                        $row['job_count'],
                    ],
                    $stats
                )
            );
        }

        // Find batches that contain jobs in IN_BATCH state
        // Include PROCESSING, READY, and PAUSED batches (PAUSED batches can be resumed)
        $batchRows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT b.id, b.state, COUNT(j.id) as job_count
             FROM google_photos_upload_batches b
             INNER JOIN google_photos_upload_jobs j ON j.batch_id = b.id
             WHERE j.state = ?
             AND b.state IN (?, ?, ?)
             GROUP BY b.id, b.state
             ORDER BY b.created_at ASC',
            [
                UploadState::IN_BATCH->value,
                BatchState::PROCESSING->value,
                BatchState::READY->value,
                BatchState::PAUSED->value,
            ]
        );

        if ([] === $batchRows) {
            $io->success('No batches found with jobs in IN_BATCH state that need processing.');

            return Command::SUCCESS;
        }

        $totalBatches = \count($batchRows);
        $batchesToProcess = $limit > 0 ? \array_slice($batchRows, 0, $limit) : $batchRows;
        $batchesToProcessCount = \count($batchesToProcess);

        $io->writeln(\sprintf(
            'Found <info>%d</info> batches with jobs in IN_BATCH state (%s).',
            $totalBatches,
            $limit > 0 ? \sprintf('processing %d', $batchesToProcessCount) : 'processing all'
        ));

        if ($dryRun) {
            $io->note('DRY RUN mode - no changes will be made');
            $io->table(
                ['Batch ID', 'Batch State', 'IN_BATCH Jobs'],
                \array_map(
                    fn (array $row): array => [
                        $row['id'],
                        $row['state'],
                        $row['job_count'],
                    ],
                    \array_slice($batchesToProcess, 0, 20)
                )
            );
            if ($batchesToProcessCount > 20) {
                $io->writeln(\sprintf('... and %d more batches', $batchesToProcessCount - 20));
            }

            return Command::SUCCESS;
        }

        $processed = 0;
        $errors = 0;
        $quotaExceeded = false;

        $progressBar = $io->createProgressBar($batchesToProcessCount);
        $progressBar->start();

        foreach ($batchesToProcess as $row) {
            try {
                $batchIdStr = \is_string($row['id'] ?? null) ? $row['id'] : '';
                if ('' === $batchIdStr) {
                    $io->warning('Batch ID is missing or invalid');
                    ++$errors;
                    $progressBar->advance();
                    continue;
                }

                $batchId = UploadBatchId::fromString($batchIdStr);
                $batch = $this->batchRepository->findById($batchId);

                if (!$batch instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch) {
                    $this->logger->warning('Batch not found', [
                        'batch_id' => $batchIdStr,
                    ]);
                    ++$errors;
                    $progressBar->advance();
                    continue;
                }

                // Check if batch has jobs in IN_BATCH state
                $hasInBatchJobs = false;
                foreach ($batch->getItems() as $item) {
                    $job = $this->jobRepository->findById($item->getJobId());
                    if ($job instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob
                        && UploadState::IN_BATCH === $job->getState()) {
                        $hasInBatchJobs = true;
                        break;
                    }
                }

                if (!$hasInBatchJobs) {
                    // Batch doesn't have IN_BATCH jobs anymore, skip it
                    $progressBar->advance();
                    continue;
                }

                // Resume paused batches before processing
                if (BatchState::PAUSED === $batch->getState()) {
                    $batch = $batch->resume();
                    $this->batchRepository->save($batch);
                    $this->logger->info('Resumed paused batch', [
                        'batch_id' => $batch->getId()->getId(),
                    ]);
                }

                // Process batch
                try {
                    $this->batchProcessor->processBatch($batch);
                    ++$processed;
                } catch (QuotaExceededException $e) {
                    $quotaExceeded = true;
                    $this->logger->warning('Quota exceeded while processing batch', [
                        'batch_id' => $batchIdStr,
                        'reset_time' => $e->getResetTime()->format('Y-m-d H:i:s'),
                    ]);
                    $io->warning(\sprintf(
                        'Quota exceeded. Batch processing stopped. Resume after: %s',
                        $e->getResetTime()->format('Y-m-d H:i:s')
                    ));
                    break; // Stop processing, quota exhausted
                } catch (\Exception $e) {
                    $this->logger->error('Error processing batch', [
                        'batch_id' => $batchIdStr,
                        'error' => $e->getMessage(),
                        'exception' => $e::class,
                    ]);
                    ++$errors;
                }

                $progressBar->advance();
            } catch (\Exception $e) {
                $batchIdStr = \is_string($row['id'] ?? null) ? $row['id'] : 'unknown';
                $this->logger->error('Error processing batch', [
                    'batch_id' => $batchIdStr,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
                ++$errors;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        if ($quotaExceeded) {
            $io->warning(\sprintf(
                'Processing stopped due to quota limit. Processed: <info>%d</info> batches. Errors: <error>%d</error>',
                $processed,
                $errors
            ));
        } else {
            $io->success(\sprintf(
                'Processed <info>%d</info> batches. Errors: <error>%d</error>',
                $processed,
                $errors
            ));
        }

        return Command::SUCCESS;
    }
}
