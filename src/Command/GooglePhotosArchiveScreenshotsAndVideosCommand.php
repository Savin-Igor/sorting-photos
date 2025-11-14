<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use Doctrine\DBAL\Connection;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadState;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google-photos:archive-screenshots-videos',
    description: 'Archive pending screenshots and videos by filename pattern'
)]
final class GooglePhotosArchiveScreenshotsAndVideosCommand extends Command
{
    private const string TABLE_NAME = 'google_photos_upload_jobs';

    // Video file extensions
    private const array VIDEO_EXTENSIONS = [
        'mp4', 'avi', 'mov', 'mkv', 'wmv', 'flv', 'webm', 'm4v',
        'mpg', 'mpeg', '3gp', '3g2', 'asf', 'rm', 'rmvb', 'vob',
        'ts', 'mts', 'm2ts', 'divx', 'xvid', 'ogv', 'f4v',
    ];

    // Screenshot filename patterns (case-insensitive)
    private const array SCREENSHOT_PATTERNS = [
        '/screenshot/i',
        '/screen shot/i',
        '/screen_shot/i',
        '/screen-shot/i',
        '/scrnsht/i',
        '/scrn/i',
        '/screen/i',
        '/screencap/i',
        '/screen cap/i',
        '/screen_cap/i',
        '/screen-cap/i',
        '/snapshot/i',
        '/snap/i',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly UploadJobRepositoryPort $jobRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be archived without actually archiving'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Running in dry-run mode - no changes will be made');
        }

        // Find all PENDING jobs - only select necessary fields to save memory
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, file_path, is_video FROM '.self::TABLE_NAME.' WHERE state = ?',
            [UploadState::PENDING->value]
        );

        if ([] === $rows) {
            $io->success('No pending files found');

            return Command::SUCCESS;
        }

        $io->info(\sprintf('Found %d pending files', \count($rows)));

        $screenshots = [];
        $videos = [];
        $skipped = 0;
        $idsToArchive = [];

        foreach ($rows as $row) {
            $filePath = \is_string($row['file_path']) ? $row['file_path'] : '';
            $fileName = \basename($filePath);
            $fileExtension = \strtolower(\pathinfo($fileName, \PATHINFO_EXTENSION));

            $isScreenshot = false;
            $isVideo = false;

            // Check if it's a screenshot by filename pattern
            foreach (self::SCREENSHOT_PATTERNS as $pattern) {
                if (\preg_match($pattern, $fileName)) {
                    $isScreenshot = true;
                    break;
                }
            }

            // Check if it's a video by extension
            if (\in_array($fileExtension, self::VIDEO_EXTENSIONS, true)) {
                $isVideo = true;
            }

            // Also check is_video flag in database (might be incorrectly set)
            $isVideoFlag = $row['is_video'] ?? 0;
            if (1 === $isVideoFlag || '1' === $isVideoFlag || true === $isVideoFlag) {
                $isVideo = true;
            }

            if ($isScreenshot || $isVideo) {
                $jobId = \is_string($row['id']) ? $row['id'] : '';
                $idsToArchive[] = $jobId;
                if ($isScreenshot) {
                    $screenshots[] = [
                        'id' => $jobId,
                        'file_path' => $filePath,
                        'reason' => 'screenshot',
                    ];
                }
                if ($isVideo) {
                    $videos[] = [
                        'id' => $jobId,
                        'file_path' => $filePath,
                        'reason' => 'video',
                    ];
                }
            } else {
                ++$skipped;
            }
        }

        $totalToArchive = \count($screenshots) + \count($videos);

        if (0 === $totalToArchive) {
            $io->success('No screenshots or videos found in pending files');

            return Command::SUCCESS;
        }

        // Display summary
        $io->section('Files to Archive');
        $io->writeln(\sprintf('Screenshots: <fg=yellow>%d</>', \count($screenshots)));
        $io->writeln(\sprintf('Videos: <fg=yellow>%d</>', \count($videos)));
        $io->writeln(\sprintf('Total: <fg=yellow>%d</>', $totalToArchive));
        $io->writeln(\sprintf('Skipped: <fg=gray>%d</>', $skipped));

        if ($dryRun) {
            $io->section('Files that would be archived');
            // Show first 50 files to avoid huge output
            $allFiles = \array_merge(
                \array_map(
                    fn (array $item): array => ['Screenshot', $item['file_path']],
                    \array_slice($screenshots, 0, 25)
                ),
                \array_map(
                    fn (array $item): array => ['Video', $item['file_path']],
                    \array_slice($videos, 0, 25)
                )
            );
            $io->table(
                ['Type', 'File Path'],
                $allFiles
            );
            if ($totalToArchive > 50) {
                $io->note(\sprintf('Showing first 50 files. Total: %d files', $totalToArchive));
            }
            $io->note('Run without --dry-run to actually archive these files');

            return Command::SUCCESS;
        }

        // Archive files - process in batches to avoid memory issues
        $io->section('Archiving Files');
        $progressBar = $io->createProgressBar($totalToArchive);
        $progressBar->start();

        $archived = 0;
        $errors = 0;
        $batchSize = 100;
        // Process in batches
        $counter = \count($idsToArchive);

        // Process in batches
        for ($i = 0; $i < $counter; $i += $batchSize) {
            $batch = \array_slice($idsToArchive, $i, $batchSize);
            $placeholders = \implode(',', \array_fill(0, \count($batch), '?'));

            try {
                // Load jobs in batch
                $batchRows = $this->connection->fetchAllAssociative(
                    'SELECT * FROM '.self::TABLE_NAME.' WHERE id IN ('.$placeholders.')',
                    $batch
                );

                foreach ($batchRows as $row) {
                    try {
                        $job = $this->hydrateJob($row);
                        $archivedJob = $job->archive();
                        $this->jobRepository->save($archivedJob);
                        ++$archived;
                    } catch (\Exception $e) {
                        ++$errors;
                        $filePath = \is_string($row['file_path']) ? $row['file_path'] : 'unknown';
                        $errorMessage = \is_string($e->getMessage()) ? $e->getMessage() : 'Unknown error';
                        $io->error(\sprintf('Failed to archive %s: %s', $filePath, $errorMessage));
                    }
                    $progressBar->advance();
                }
            } catch (\Exception $e) {
                ++$errors;
                $io->error(\sprintf('Failed to process batch: %s', $e->getMessage()));
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        // Summary
        $io->section('Summary');
        $io->writeln(\sprintf('Archived: <fg=green>%d</>', $archived));
        if ($errors > 0) {
            $io->writeln(\sprintf('Errors: <fg=red>%d</>', $errors));
        }

        if ($archived > 0) {
            $io->success(\sprintf('Successfully archived %d file(s)', $archived));
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateJob(array $row): UploadJob
    {
        return UploadJob::fromArray($row);
    }
}
