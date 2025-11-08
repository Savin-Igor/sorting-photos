<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use SortingPhotosByDate\Application\Service\GooglePhotos\FileScanner;
use SortingPhotosByDate\Application\Service\GooglePhotos\UploadOrchestrator;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\DatabaseSchemaInitializer;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google-photos:upload',
    description: 'Upload files to Google Photos'
)]
final class UploadToGooglePhotosCommand extends Command
{
    public function __construct(
        private readonly UploadOrchestrator $orchestrator,
        private readonly FileScanner $fileScanner,
        private readonly DatabaseSchemaInitializer $schemaInitializer,
        private readonly UploadJobRepositoryPort $jobRepository,
        private readonly LoggerPort $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Source directory to scan for files')
            ->addOption('scan-only', null, InputOption::VALUE_NONE, 'Only scan files, do not upload')
            ->addOption('init-schema', null, InputOption::VALUE_NONE, 'Initialize database schema');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Initialize database schema
        if ($input->getOption('init-schema')) {
            $io->info('Initializing database schema...');
            try {
                $this->schemaInitializer->initializeSchema();
                $io->success('Database schema initialized successfully');
            } catch (\Exception $e) {
                $io->error(\sprintf('Failed to initialize schema: %s', $e->getMessage()));
                $this->logger->error('Schema initialization failed', [
                    'error' => $e->getMessage(),
                ]);

                return Command::FAILURE;
            }
        }

        // Scan only
        if ($input->getOption('scan-only')) {
            $sourcePath = $input->getOption('source');
            // Use SOURCE_DIRECTORY from environment if not provided
            if (null === $sourcePath) {
                $sourcePath = getenv('SOURCE_DIRECTORY');
                if (false === $sourcePath || '' === $sourcePath) {
                    $io->error('Source path is required. Set --source option or SOURCE_DIRECTORY environment variable');

                    return Command::FAILURE;
                }
            }

            $io->info(\sprintf('Scanning files in: %s', $sourcePath));
            $count = $this->fileScanner->scanAndCreateJobs($sourcePath);
            $io->success(\sprintf('Scanned and created %d upload jobs', $count));

            return Command::SUCCESS;
        }

        // Auto-scan if SOURCE_DIRECTORY is set and no jobs exist
        $sourcePath = $input->getOption('source') ?: getenv('SOURCE_DIRECTORY');
        if (false !== $sourcePath && '' !== $sourcePath) {
            $pendingJob = $this->jobRepository->findNextPendingOrResumable();
            if (!$pendingJob instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob) {
                $io->info(\sprintf('No pending jobs found. Scanning source directory: %s', $sourcePath));
                $count = $this->fileScanner->scanAndCreateJobs($sourcePath);
                $io->info(\sprintf('Scanned and created %d upload jobs', $count));
            }
        }

        // Start orchestrator
        $io->info('Starting upload orchestrator...');
        $this->logger->info('Upload orchestrator started');

        try {
            $this->orchestrator->run();
            $io->success('Upload completed');
            $this->logger->info('Upload orchestrator completed');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(\sprintf('Upload failed: %s', $e->getMessage()));
            $this->logger->error('Upload orchestrator failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
