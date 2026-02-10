<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use SortingPhotosByDate\Application\Service\GooglePhotos\FileScanner;
use SortingPhotosByDate\Application\Service\GooglePhotos\UploadOrchestrator;
use SortingPhotosByDate\Application\Service\GooglePhotos\DistributedLockManager;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\DatabaseSchemaInitializer;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;

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
        private readonly DistributedLockManager $lockManager,
        private readonly LoggerPort $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Source directory to scan for files')
            ->addOption('scan-only', null, InputOption::VALUE_NONE, 'Only scan files, do not upload')
            ->addOption('init-schema', null, InputOption::VALUE_NONE, 'Initialize database schema')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force release lock and continue')
            ->addOption('no-interactive', null, InputOption::VALUE_NONE, 'Do not ask interactive questions');
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
                $sourcePath = $_ENV['SOURCE_DIRECTORY'] ?? $_SERVER['SOURCE_DIRECTORY'] ?? getenv('SOURCE_DIRECTORY') ?: null;
                if (null === $sourcePath || '' === $sourcePath) {
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
        $sourcePath = $input->getOption('source') ?: ($_ENV['SOURCE_DIRECTORY'] ?? $_SERVER['SOURCE_DIRECTORY'] ?? getenv('SOURCE_DIRECTORY') ?: null);
        if (null !== $sourcePath && '' !== $sourcePath) {
            $pendingJob = $this->jobRepository->findNextPendingOrResumable();
            if (!$pendingJob instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob) {
                $io->info(\sprintf('No pending jobs found. Scanning source directory: %s', $sourcePath));
                $count = $this->fileScanner->scanAndCreateJobs($sourcePath);
                $io->info(\sprintf('Scanned and created %d upload jobs', $count));
            }
        }

        // Check for lock conflicts before starting orchestrator
        $lockName = 'google_photos_upload_orchestrator';
        $lockInfo = $this->lockManager->getLockInfo($lockName);

        if (null !== $lockInfo) {
            $isAlive = $this->lockManager->isLockOwnerAlive($lockName);

            if (!$isAlive) {
                $io->warning(\sprintf(
                    'Found stale lock from dead process (PID: %s, acquired: %s).',
                    $lockInfo['process_id'],
                    $lockInfo['acquired_at']
                ));

                if ($input->getOption('force') || $this->askForceRelease($input, $io)) {
                    $this->lockManager->forceReleaseLock($lockName);
                    $io->success('Stale lock released');
                } else {
                    $io->note('Use --force flag to release the lock automatically');

                    return Command::FAILURE;
                }
            } else {
                $io->warning(\sprintf(
                    'Another orchestrator instance is already running (PID: %s, acquired: %s, expires: %s).',
                    $lockInfo['process_id'],
                    $lockInfo['acquired_at'],
                    $lockInfo['expires_at']
                ));

                // Handle --force flag for active processes
                if ($input->getOption('force')) {
                    if ($this->lockManager->forceReleaseLock($lockName)) {
                        $io->success('Lock released');
                    } else {
                        $io->error('Failed to release lock');

                        return Command::FAILURE;
                    }
                } elseif (!$input->getOption('no-interactive') && $input->isInteractive()) {
                    $question = new ChoiceQuestion(
                        'What would you like to do?',
                        [
                            'wait' => 'Wait for the other process to finish',
                            'force' => 'Force release lock and start new process',
                            'cancel' => 'Cancel and exit',
                        ],
                        'cancel'
                    );

                    $choice = $io->askQuestion($question);

                    if ('force' === $choice) {
                        if ($this->lockManager->forceReleaseLock($lockName)) {
                            $io->success('Lock released');
                        } else {
                            $io->error('Failed to release lock');

                            return Command::FAILURE;
                        }
                    } elseif ('wait' === $choice) {
                        $io->info('Waiting for the other process to finish...');
                        $maxWait = 300; // 5 minutes
                        $waited = 0;
                        $progressBar = $io->createProgressBar($maxWait);
                        $progressBar->start();
                        while ($waited < $maxWait && null !== $this->lockManager->getLockInfo($lockName)) {
                            \sleep(5);
                            $waited += 5;
                            $progressBar->advance(5);
                        }
                        $progressBar->finish();
                        $io->newLine();

                        if (null !== $this->lockManager->getLockInfo($lockName)) {
                            $io->error('Timeout waiting for lock release');

                            return Command::FAILURE;
                        }
                    } else {
                        return Command::FAILURE;
                    }
                } else {
                    $io->note('Use --force flag to release the lock, or wait for the process to finish');

                    return Command::FAILURE;
                }
            }
        }

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

    private function askForceRelease(InputInterface $input, SymfonyStyle $io): bool
    {
        if ($input->getOption('no-interactive') || !$input->isInteractive()) {
            return false;
        }

        $question = new ConfirmationQuestion(
            'Do you want to release the stale lock and continue? [y/N]: ',
            false
        );

        $result = $io->askQuestion($question);

        return \is_bool($result) && $result;
    }
}
