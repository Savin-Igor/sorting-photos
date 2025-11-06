<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use SortingPhotosByDate\Domain\Event\FileDiscovered;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\ScannerPort;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Console command to scan directory and publish FileDiscovered events.
 */
final class ScanFilesCommand extends Command
{
    protected static ?string $defaultName = 'scan:files';
    protected static ?string $defaultDescription = 'Scan directory and publish FileDiscovered events to queue';

    public function __construct(
        private readonly ScannerPort $scanner,
        private readonly MessageBusInterface $messageBus,
        private readonly FilesystemPort $filesystem,
        private readonly LoggerPort $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('scan:files')
            ->setDescription('Scan directory and publish FileDiscovered events to queue')
            ->addArgument(
                'source-directory',
                InputArgument::REQUIRED,
                'Source directory to scan'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Run in dry-run mode (no actual processing)'
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceDirectory = (string) $input->getArgument('source-directory');
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Running in DRY-RUN mode');
            $this->logger->info('Starting file scan in dry-run mode', [
                'source_directory' => $sourceDirectory,
            ]);
        } else {
            $io->info('Starting file scan');
            $this->logger->info('Starting file scan', [
                'source_directory' => $sourceDirectory,
            ]);
        }

        try {
            $files = $this->scanner->scan($sourceDirectory);
            $fileCount = 0;
            $discoveredCount = 0;
            $skippedCount = 0;

            foreach ($files as $filePath) {
                ++$fileCount;

                // Check if file exists
                if (!$this->filesystem->exists($filePath)) {
                    $this->logger->warning('File does not exist, skipping', [
                        'file_path' => $filePath->getPath(),
                    ]);
                    ++$skippedCount;
                    continue;
                }

                // Get file size
                $fileSize = $this->filesystem->getSize($filePath);

                // Create and dispatch FileDiscovered event
                $event = new FileDiscovered($filePath, $fileSize);
                $this->messageBus->dispatch($event);

                ++$discoveredCount;

                if (0 === $fileCount % 100) {
                    $this->logger->debug('Scanned files', [
                        'count' => $fileCount,
                        'discovered' => $discoveredCount,
                        'skipped' => $skippedCount,
                    ]);
                }
            }

            if (0 === $fileCount) {
                $io->warning('No files found in directory');
                $this->logger->info('No files found in directory', [
                    'source_directory' => $sourceDirectory,
                ]);

                return Command::SUCCESS;
            }

            $io->success(\sprintf(
                'Scan completed: %d files found, %d events published, %d skipped',
                $fileCount,
                $discoveredCount,
                $skippedCount
            ));

            $this->logger->info('File scan completed', [
                'source_directory' => $sourceDirectory,
                'total_files' => $fileCount,
                'discovered' => $discoveredCount,
                'skipped' => $skippedCount,
                'dry_run' => $dryRun,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(\sprintf('Error during scan: %s', $e->getMessage()));
            $this->logger->error('File scan failed', [
                'source_directory' => $sourceDirectory,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return Command::FAILURE;
        }
    }
}
