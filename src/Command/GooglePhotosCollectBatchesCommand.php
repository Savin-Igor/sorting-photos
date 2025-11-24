<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use Doctrine\DBAL\Connection;
use SortingPhotosByDate\Application\Service\GooglePhotos\BatchCollector;
use SortingPhotosByDate\Application\Service\GooglePhotos\BatchProcessor;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadState;
use SortingPhotosByDate\Ports\LoggerPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google-photos:collect-batches',
    description: 'Collect batches from UPLOADED files and process them'
)]
final class GooglePhotosCollectBatchesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BatchCollector $batchCollector,
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
                'Show what would be collected without making changes'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Limit number of batches to collect and process',
                '0'
            )
            ->addOption(
                'collect-only',
                null,
                InputOption::VALUE_NONE,
                'Only collect batches, do not process them'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $limitOption = $input->getOption('limit');
        $limit = \is_string($limitOption) || \is_int($limitOption) ? (int) $limitOption : 0;
        $collectOnly = $input->getOption('collect-only');

        // Show statistics
        $stats = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM google_photos_upload_jobs WHERE state = ? AND batch_id IS NULL',
            [UploadState::UPLOADED->value]
        );

        $uploadedCount = \is_numeric($stats) ? (int) $stats : 0;

        if (0 === $uploadedCount) {
            $io->success('No UPLOADED files found that need batch collection.');

            return Command::SUCCESS;
        }

        $io->section('UPLOADED Files Statistics');
        $io->writeln(\sprintf('Found <info>%d</info> files in UPLOADED state waiting for batch collection.', $uploadedCount));

        if ($dryRun) {
            $io->note('DRY RUN mode - no changes will be made');
            $io->writeln('Would collect batches from UPLOADED files and process them.');

            return Command::SUCCESS;
        }

        $collected = 0;
        $processed = 0;
        $errors = 0;
        $quotaExceeded = false;
        $totalItemsCollected = 0;

        $io->section('Collecting and Processing Batches');

        while (true) {
            // Check limit
            if ($limit > 0 && $collected >= $limit) {
                $io->writeln(\sprintf('Reached limit of %d batches.', $limit));
                break;
            }

            // Check remaining UPLOADED files before collecting
            $remainingBefore = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM google_photos_upload_jobs WHERE state = ? AND batch_id IS NULL',
                [UploadState::UPLOADED->value]
            );
            $remainingCount = \is_numeric($remainingBefore) ? (int) $remainingBefore : 0;

            if (0 === $remainingCount) {
                $io->writeln('No more UPLOADED files remaining.');
                break;
            }

            $this->logger->debug('Checking for UPLOADED files to collect', [
                'remaining_count' => $remainingCount,
                'batches_collected' => $collected,
            ]);

            // Collect batch
            $batch = $this->batchCollector->collectBatch();

            if (!$batch instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch) {
                // No more batches to collect - check why
                $stillRemaining = $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM google_photos_upload_jobs WHERE state = ? AND batch_id IS NULL',
                    [UploadState::UPLOADED->value]
                );
                $stillRemainingCount = \is_numeric($stillRemaining) ? (int) $stillRemaining : 0;

                if ($stillRemainingCount > 0) {
                    $io->warning(\sprintf(
                        'Batch collector returned null but <info>%d</info> UPLOADED files still remain. '.
                        'This may be due to size constraints. Files will be processed individually.',
                        $stillRemainingCount
                    ));
                    $this->logger->warning('Batch collector returned null but files remain', [
                        'remaining_count' => $stillRemainingCount,
                    ]);
                }
                break;
            }

            ++$collected;
            $batchId = $batch->getId()->getId();
            $itemCount = \count($batch->getItems());
            $totalItemsCollected += $itemCount;

            $io->writeln(\sprintf(
                '[%d/%d] Collected batch <info>%s</info> with <info>%d</info> items (Remaining: <comment>%d</comment>)',
                $collected,
                $limit > 0 ? $limit : '∞',
                $batchId,
                $itemCount,
                $remainingCount
            ));

            $this->logger->info('Batch collected for processing', [
                'batch_id' => $batchId,
                'item_count' => $itemCount,
                'remaining_uploaded' => $remainingCount,
                'total_batches_collected' => $collected,
            ]);

            if ($collectOnly) {
                $io->writeln('  → Batch collected (collect-only mode, skipping processing)');
                continue;
            }

            // Process batch
            try {
                $this->batchProcessor->processBatch($batch);
                ++$processed;
                $io->writeln(\sprintf('  → Batch <info>%s</info> processed successfully', $batchId));
            } catch (QuotaExceededException $e) {
                $quotaExceeded = true;
                $this->logger->warning('Quota exceeded while processing batch', [
                    'batch_id' => $batchId,
                    'reset_time' => $e->getResetTime()->format('Y-m-d H:i:s'),
                ]);
                $io->warning(\sprintf(
                    'Quota exceeded. Batch processing stopped. Resume after: %s',
                    $e->getResetTime()->format('Y-m-d H:i:s')
                ));
                break; // Stop processing, quota exhausted
            } catch (\Exception $e) {
                $this->logger->error('Error processing batch', [
                    'batch_id' => $batchId,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
                ++$errors;
                $io->error(\sprintf('  → Error processing batch %s: %s', $batchId, $e->getMessage()));
            }
        }

        $io->newLine();

        if ($quotaExceeded) {
            $io->warning(\sprintf(
                'Processing stopped due to quota limit. Collected: <info>%d</info> batches, Processed: <info>%d</info>, Errors: <error>%d</error>',
                $collected,
                $processed,
                $errors
            ));
        } else {
            // Final statistics
            $finalRemaining = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM google_photos_upload_jobs WHERE state = ? AND batch_id IS NULL',
                [UploadState::UPLOADED->value]
            );
            $finalRemainingCount = \is_numeric($finalRemaining) ? (int) $finalRemaining : 0;

            if ($collectOnly) {
                $io->success(\sprintf(
                    'Collected <info>%d</info> batches with <info>%d</info> items (collect-only mode). Remaining UPLOADED: <comment>%d</comment>',
                    $collected,
                    $totalItemsCollected,
                    $finalRemainingCount
                ));
            } else {
                $io->success(\sprintf(
                    'Collected <info>%d</info> batches (<info>%d</info> items), Processed: <info>%d</info>, Errors: <error>%d</error>. Remaining UPLOADED: <comment>%d</comment>',
                    $collected,
                    $totalItemsCollected,
                    $processed,
                    $errors,
                    $finalRemainingCount
                ));
            }

            if ($finalRemainingCount > 0) {
                $io->note(\sprintf(
                    'There are still <info>%d</info> UPLOADED files remaining. '.
                    'Run the command again to process them, or check if they exceed batch size limits.',
                    $finalRemainingCount
                ));
            }
        }

        return Command::SUCCESS;
    }
}
