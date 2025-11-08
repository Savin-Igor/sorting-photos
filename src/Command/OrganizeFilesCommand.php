<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use SortingPhotosByDate\Application\Service\FileOrganizingService;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Helper\ByteFormatter;
use SortingPhotosByDate\Infrastructure\Statistics\RedisStatisticsService;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\MetadataRepositoryPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'organize:files',
    description: 'Organize files from source to destination directory with date-based sorting'
)]
final class OrganizeFilesCommand extends Command
{
    public function __construct(
        private readonly FileOrganizingService $organizingService,
        private readonly FilesystemPort $filesystem,
        private readonly MetadataRepositoryPort $repository,
        private readonly LoggerPort $logger,
        private readonly ByteFormatter $byteFormatter,
        private readonly ?RedisStatisticsService $statistics = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'Source directory path (overrides SOURCE_DIRECTORY env var)')
            ->addOption('destination', 'd', InputOption::VALUE_OPTIONAL, 'Destination directory path (overrides DESTINATION_DIRECTORY env var)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run in dry-run mode (no actual changes)')
            ->setHelp(
                <<<'HELP'
The <info>%command.name%</info> command organizes files from source directory to destination directory
with date-based sorting. Files are scanned recursively, metadata is extracted, and files are
organized according to the configured organizer policy.

<info>php %command.full_name%</info>

You can override environment variables with options:
<info>php %command.full_name% --source=/path/to/source --destination=/path/to/destination</info>

To run in dry-run mode:
<info>php %command.full_name% --dry-run</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get directories from options or environment
        $sourceDirectory = $input->getOption('source')
            ?? getenv('SOURCE_DIRECTORY')
            ?: throw new \RuntimeException('Source directory not specified. Use --source option or set SOURCE_DIRECTORY environment variable.');

        $destinationDirectory = $input->getOption('destination')
            ?? getenv('DESTINATION_DIRECTORY')
            ?: throw new \RuntimeException('Destination directory not specified. Use --destination option or set DESTINATION_DIRECTORY environment variable.');

        $dryRun = $input->getOption('dry-run');

        // Validate directories exist
        if (!\is_dir($sourceDirectory)) {
            $io->error(\sprintf('Source directory does not exist: %s', $sourceDirectory));

            return Command::FAILURE;
        }

        // Ensure destination directory exists
        $destinationPath = new FilePath($destinationDirectory);
        if (!\is_dir($destinationDirectory)) {
            $this->filesystem->ensureDirectory($destinationPath);
        }

        // Get host paths from environment (for display purposes)
        $sourceDirectoryHost = getenv('SOURCE_DIRECTORY_HOST') ?: ($_ENV['SOURCE_DIRECTORY_HOST'] ?? null);
        $destinationDirectoryHost = getenv('DESTINATION_DIRECTORY_HOST') ?: ($_ENV['DESTINATION_DIRECTORY_HOST'] ?? null);

        // Extract host path from SOURCE_DIRECTORY_HOST if it contains colon (docker volume format)
        if ($sourceDirectoryHost && \str_contains((string) $sourceDirectoryHost, ':')) {
            $sourceDirectoryHost = \explode(':', (string) $sourceDirectoryHost)[0];
        }
        if ($destinationDirectoryHost && \str_contains((string) $destinationDirectoryHost, ':')) {
            $destinationDirectoryHost = \explode(':', (string) $destinationDirectoryHost)[0];
        }

        // Display startup info
        $io->title('File Sorting Application');
        if ($sourceDirectoryHost) {
            $io->writeln(\sprintf('Source (host): <info>%s</info>', $sourceDirectoryHost));
        }
        $io->writeln(\sprintf('Source (container): <comment>%s</comment>', $sourceDirectory));
        if ($destinationDirectoryHost) {
            $io->writeln(\sprintf('Destination (host): <info>%s</info>', $destinationDirectoryHost));
        }
        $io->writeln(\sprintf('Destination (container): <comment>%s</comment>', $destinationDirectory));
        if ($dryRun) {
            $io->note('Running in DRY-RUN mode');
        }
        $io->newLine();

        // Log startup information
        $this->logger->info('=== File Sorting Application Started ===');
        $this->logger->debug('Source Directory (host): '.($sourceDirectoryHost ?? 'N/A'));
        $this->logger->debug('Source Directory (container): '.$sourceDirectory);
        $this->logger->debug('Destination Directory (host): '.($destinationDirectoryHost ?? 'N/A'));
        $this->logger->debug('Destination Directory (container): '.$destinationDirectory);
        $this->logger->debug('Dry Run Mode: '.($dryRun ? 'YES' : 'NO'));

        // Initialize statistics if available
        if ($this->statistics instanceof RedisStatisticsService) {
            $this->statistics->reset();
        }

        // Organize files
        $io->section('Processing Files');
        $result = $this->organizingService->organize($sourceDirectory, $destinationDirectory, $dryRun);

        // Display results
        $io->section('Results');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total files', (string) $result['total_files']],
                ['Discovered', (string) $result['discovered']],
                ['Skipped', (string) $result['skipped']],
                ['Source size (before)', $this->byteFormatter->format($result['source_size_before'])],
                ['Destination size (before)', $this->byteFormatter->format($result['destination_size_before'])],
                ['Source size (after)', $this->byteFormatter->format($result['source_size_after'])],
                ['Destination size (after)', $this->byteFormatter->format($result['destination_size_after'])],
            ]
        );

        // Calculate moved size
        $movedSize = $result['destination_size_after'] - $result['destination_size_before'];
        $remainingSize = $result['source_size_after'];

        $io->section('Statistics');
        $io->writeln(\sprintf('Size moved to destination: <info>%s</info>', $this->byteFormatter->format($movedSize)));
        $io->writeln(\sprintf('Size remaining in source: <info>%s</info>', $this->byteFormatter->format($remainingSize)));

        // Display processing statistics from Redis if available
        if ($this->statistics instanceof RedisStatisticsService) {
            $stats = $this->statistics->getStats();
            if ($stats['total'] > 0) {
                $io->newLine();
                $io->section('Processing Statistics');
                $io->table(
                    ['Metric', 'Value'],
                    [
                        ['Total files', (string) $stats['total']],
                        ['Processed', (string) $stats['processed']],
                        ['Skipped', (string) $stats['skipped']],
                        ['Errors', (string) $stats['errors']],
                    ]
                );

                if ($stats['skipped'] > 0) {
                    $io->newLine();
                    $io->section('Skipped Files Details');
                    $io->writeln(\sprintf('Total skipped: <comment>%d</comment>', $stats['skipped']));
                    if (isset($stats['duplicates']) && $stats['duplicates'] > 0) {
                        $io->writeln(\sprintf('  - Duplicates: <comment>%d</comment>', $stats['duplicates']));
                    }
                    if (isset($stats['already_processed']) && $stats['already_processed'] > 0) {
                        $io->writeln(\sprintf('  - Already processed: <comment>%d</comment>', $stats['already_processed']));
                    }
                }
            }
        }

        // Display duplicate statistics
        if ($result['duplicate_files'] > 0) {
            $io->newLine();
            $io->section('Duplicate Files');
            $io->writeln(\sprintf(
                'Duplicate files skipped: <comment>%d</comment> (<comment>%s</comment>)',
                $result['duplicate_files'],
                $this->byteFormatter->format($result['duplicate_size'])
            ));
        }

        // Integrity checks
        $warnings = [];
        $errors = [];

        // Check 1: Verify total size matches (accounting for duplicates)
        $totalSizeAfter = $result['destination_size_after'] + $result['source_size_after'];
        $totalSizeBefore = $result['source_size_before'] + $result['destination_size_before'];

        if ($totalSizeAfter !== $totalSizeBefore) {
            $difference = \abs($totalSizeAfter - $totalSizeBefore);
            $errorMsg = \sprintf(
                'ALERT!!! Total size mismatch: Expected %s, Got %s (Difference: %s)',
                $this->byteFormatter->format($totalSizeBefore),
                $this->byteFormatter->format($totalSizeAfter),
                $this->byteFormatter->format($difference)
            );
            $errors[] = $errorMsg;
            $io->error($errorMsg);
        }

        // Check 2: Verify expected final size (accounting for duplicates)
        $expectedFinalSize = $result['source_size_before'] + $result['destination_size_before'] - $result['duplicate_size'];
        $sizeDifference = \abs($result['destination_size_after'] - $expectedFinalSize);
        $sizeDifferencePercent = $expectedFinalSize > 0 ? ($sizeDifference / $expectedFinalSize) * 100 : 0;

        if ($sizeDifferencePercent > 0.1) { // More than 0.1% difference
            $warningMsg = \sprintf(
                'Destination size differs from expected: Expected %s, Got %s (Difference: %s, %.2f%%)',
                $this->byteFormatter->format($expectedFinalSize),
                $this->byteFormatter->format($result['destination_size_after']),
                $this->byteFormatter->format($sizeDifference),
                $sizeDifferencePercent
            );
            $warnings[] = $warningMsg;
            $io->warning($warningMsg);
        }

        // Check 3: Verify files were actually moved (if not dry-run)
        if (!$dryRun && 0 === $movedSize && $result['source_size_before'] > 0) {
            $warningMsg = 'No files were moved, but source directory is not empty';
            $warnings[] = $warningMsg;
            $io->warning($warningMsg);
        }

        // Log warnings and errors
        foreach ($warnings as $warning) {
            $this->logger->warning($warning);
        }

        foreach ($errors as $error) {
            $this->logger->error($error);
        }

        if (empty($errors) && empty($warnings)) {
            $io->success('✅ All integrity checks passed!');
            $this->logger->info('✅ All integrity checks passed!');
        }

        $io->newLine();
        $io->success('=== File Sorting Application Completed ===');
        $this->logger->info('=== File Sorting Application Completed ===');

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }
}
