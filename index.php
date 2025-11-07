<?php

declare(strict_types=1);

use SortingPhotosByDate\Command\ScanFilesCommand;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Config\ContainerFactory;
use SortingPhotosByDate\Infrastructure\Helper\ByteFormatter;
use SortingPhotosByDate\Infrastructure\Helper\DirectorySizeCalculator;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\MetadataRepositoryPort;
use SortingPhotosByDate\Ports\ScannerPort;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Messenger\MessageBusInterface;

require_once __DIR__.'/vendor/autoload.php';

// Load environment variables
if (file_exists(__DIR__.'/.env')) {
    new Dotenv()->load(__DIR__.'/.env');
}

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '550M');
ini_set('display_startup_errors', '1');

try {
    // Build and configure DI container
    $containerFactory = new ContainerFactory(__DIR__);
    $container = $containerFactory->build();

    // Validate required parameters
    $containerFactory->validateRequiredParameters($container);

    // Get parameters from container
    $sourceDirectory = $container->getParameter('app.source_directory');
    $destinationDirectory = $container->getParameter('app.destination_directory');
    $dryRun = $container->getParameter('app.dry_run');

    // Ensure var directory exists
    $varDir = __DIR__.'/var';
    if (!is_dir($varDir)) {
        mkdir($varDir, 0755, true);
    }

    // Ensure log directory exists
    $logDir = __DIR__.'/var/log';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Get message bus from container
    $messageBus = $container->get(MessageBusInterface::class);

    // Get services after compilation
    $logger = $container->get(LoggerPort::class);
    $filesystem = $container->get(FilesystemPort::class);
    $scanner = $container->get(ScannerPort::class);
    $repository = $container->get(MetadataRepositoryPort::class);

    // Create helper instances
    $byteFormatter = new ByteFormatter();
    $directorySizeCalculator = new DirectorySizeCalculator($filesystem);

    // Create console output for progress bar
    $output = new ConsoleOutput();

    // Get log file name from container (includes timestamp)
    $logFileName = $container->getParameter('app.log_file');
    $logFilePath = __DIR__.'/var/log/'.$logFileName;

    // Display log file info in console
    $output->writeln(\sprintf('Log file: <comment>%s</comment>', $logFilePath));
    $output->writeln('');

    // Validate directories exist (they must be mounted/created on host)
    if (!is_dir($sourceDirectory)) {
        throw new RuntimeException("Source directory does not exist: {$sourceDirectory}. Please ensure it exists and is mounted in Docker.");
    }

    // Ensure destination directory exists (create if needed, but only if we have permissions)
    $destinationPath = new FilePath($destinationDirectory);
    if (!is_dir($destinationDirectory)) {
        // Try to create, but don't fail if we don't have permissions
        // Directory should be created on host or mounted in Docker
        $filesystem->ensureDirectory($destinationPath);
    }

    // Try to get host paths from environment (for display purposes)
    $sourceDirectoryHost = getenv('SOURCE_DIRECTORY_HOST') ?: ($_ENV['SOURCE_DIRECTORY_HOST'] ?? null);
    $destinationDirectoryHost = getenv('DESTINATION_DIRECTORY_HOST') ?: ($_ENV['DESTINATION_DIRECTORY_HOST'] ?? null);

    // Extract host path from SOURCE_DIRECTORY_HOST if it contains colon (docker volume format)
    if ($sourceDirectoryHost && str_contains((string) $sourceDirectoryHost, ':')) {
        $sourceDirectoryHost = explode(':', (string) $sourceDirectoryHost)[0];
    }
    if ($destinationDirectoryHost && str_contains((string) $destinationDirectoryHost, ':')) {
        $destinationDirectoryHost = explode(':', (string) $destinationDirectoryHost)[0];
    }

    // Log startup information (console: important, file: detailed)
    $logger->info('=== File Sorting Application Started ===');
    $logger->debug('Source Directory (host): '.($sourceDirectoryHost ?? 'N/A'));
    $logger->debug('Source Directory (container): '.$sourceDirectory);
    $logger->debug('Destination Directory (host): '.($destinationDirectoryHost ?? 'N/A'));
    $logger->debug('Destination Directory (container): '.$destinationDirectory);
    $logger->debug('Dry Run Mode: '.($dryRun ? 'YES' : 'NO'));

    // Display startup info in console
    $output->writeln('<info>=== File Sorting Application Started ===</info>');
    if ($sourceDirectoryHost) {
        $output->writeln(\sprintf('Source (host): <info>%s</info>', $sourceDirectoryHost));
    }
    $output->writeln(\sprintf('Source (container): <comment>%s</comment>', $sourceDirectory));
    if ($destinationDirectoryHost) {
        $output->writeln(\sprintf('Destination (host): <info>%s</info>', $destinationDirectoryHost));
    }
    $output->writeln(\sprintf('Destination (container): <comment>%s</comment>', $destinationDirectory));
    if ($dryRun) {
        $output->writeln('<comment>Running in DRY-RUN mode</comment>');
    }
    $output->writeln('');

    // Calculate directory sizes before processing
    $output->write('Calculating directory sizes... ');
    $sourceDirectorySize = $directorySizeCalculator->calculate($sourceDirectory);
    $destinationDirectorySizeBefore = $directorySizeCalculator->calculate($destinationDirectory);

    // Get duplicate statistics from database
    $duplicateStats = $repository->getDuplicateStats();
    $duplicateSize = $duplicateStats['duplicate_size'] ?? 0;

    // Expected final size = source size + destination size - duplicate size (duplicates won't be copied)
    $expectedFinalSize = $sourceDirectorySize + $destinationDirectorySizeBefore - $duplicateSize;

    $output->writeln('<info>Done</info>');
    $output->writeln('');

    // Display size information
    $output->writeln(\sprintf('Source Directory: <info>%s</info>', $byteFormatter->format($sourceDirectorySize)));
    $output->writeln(\sprintf('Destination Directory (before): <info>%s</info>', $byteFormatter->format($destinationDirectorySizeBefore)));
    if ($duplicateSize > 0) {
        $output->writeln(\sprintf(
            'Duplicate files (will be skipped): <comment>%s</comment> (%d files)',
            $byteFormatter->format($duplicateSize),
            $duplicateStats['duplicate_files'] ?? 0
        ));
    }
    $output->writeln(\sprintf('Expected Final Size: <info>%s</info>', $byteFormatter->format($expectedFinalSize)));
    $output->writeln('');

    // Log detailed information to file
    $logger->debug('Source Directory Size: '.$byteFormatter->format($sourceDirectorySize));
    $logger->debug('Destination Directory Size Before: '.$byteFormatter->format($destinationDirectorySizeBefore));
    if ($duplicateSize > 0) {
        $logger->debug('Duplicate files detected', [
            'duplicate_count' => $duplicateStats['duplicate_files'] ?? 0,
            'duplicate_size' => $duplicateSize,
            'total_files' => $duplicateStats['total_files'] ?? 0,
            'unique_files' => $duplicateStats['unique_files'] ?? 0,
        ]);
    }
    $logger->debug('Expected Final Size: '.$byteFormatter->format($expectedFinalSize));

    // Initialize Redis statistics service if available and reset counters
    $statisticsService = null;
    if ($container->has(SortingPhotosByDate\Infrastructure\Statistics\RedisStatisticsService::class)) {
        $statisticsService = $container->get(SortingPhotosByDate\Infrastructure\Statistics\RedisStatisticsService::class);
        $statisticsService->reset();
    }

    // Check if async mode is enabled
    $asyncMode = getenv('ASYNC_MODE') ?: ($_ENV['ASYNC_MODE'] ?? 'false');
    $asyncMode = filter_var($asyncMode, FILTER_VALIDATE_BOOLEAN);

    // Create and execute ScanFilesCommand
    $scanCommand = new ScanFilesCommand($scanner, $messageBus, $filesystem, $logger, $statisticsService);
    $input = new ArrayInput([
        'source-directory' => $sourceDirectory,
        '--dry-run' => $dryRun,
    ]);

    $output->writeln('<info>Starting file scan and processing...</info>');
    $logger->debug('Starting file scan and processing...');

    $exitCode = $scanCommand->run($input, $output);

    if (0 !== $exitCode) {
        $output->writeln('<error>File scan failed!</error>');
        $logger->error('Scan command failed with exit code: '.$exitCode);
        throw new RuntimeException('File scan failed');
    }

    $logger->debug('Scan command completed successfully');

    // Files are being processed by workers (async) or immediately (sync)
    // Use 'make show-progress' command to monitor progress in real-time
    if (null !== $statisticsService) {
        $stats = $statisticsService->getStats();
        $totalFiles = $stats['total'];

        if ($totalFiles > 0) {
            $output->writeln('');
            $output->writeln(\sprintf('<info>Processing started: %d files queued</info>', $totalFiles));
            if ($asyncMode) {
                $output->writeln('<comment>Files are being processed by workers in the background.</comment>');
                $output->writeln('<comment>Monitor progress with: <info>make show-progress</info></comment>');
            } else {
                $output->writeln('<comment>Files are being processed synchronously.</comment>');
                $output->writeln('<comment>Monitor progress with: <info>make show-progress</info></comment>');
            }
            $output->writeln('');
        }
    }

    // Calculate directory sizes after processing
    $output->write('Calculating directory sizes after processing... ');
    $destinationDirectorySizeAfter = $directorySizeCalculator->calculate($destinationDirectory);
    $sourceDirectorySizeAfter = $directorySizeCalculator->calculate($sourceDirectory);
    $output->writeln('<info>Done</info>');
    $output->writeln('');

    $logger->debug('Destination Directory Size After: '.$byteFormatter->format($destinationDirectorySizeAfter));
    $logger->debug('Source Directory Size After: '.$byteFormatter->format($sourceDirectorySizeAfter));

    // Verify integrity
    $movedSize = $destinationDirectorySizeAfter - $destinationDirectorySizeBefore;
    $remainingSize = $sourceDirectorySizeAfter;

    // Get updated duplicate statistics
    $finalDuplicateStats = $repository->getDuplicateStats();

    // Get skipped files statistics from Redis
    $skippedStats = [
        'total' => 0,
        'duplicates' => 0,
        'already_processed' => 0,
    ];
    if (null !== $statisticsService) {
        $stats = $statisticsService->getStats();
        $skippedStats = [
            'total' => $stats['skipped'],
            'duplicates' => $stats['duplicates'],
            'already_processed' => $stats['already_processed'],
        ];
    }

    // Display results
    $output->writeln('<info>=== Processing Results ===</info>');
    $output->writeln(\sprintf('Size moved to destination: <info>%s</info>', $byteFormatter->format($movedSize)));
    $output->writeln(\sprintf('Size remaining in source: <info>%s</info>', $byteFormatter->format($remainingSize)));

    // Display processing statistics
    if (null !== $statisticsService) {
        $allStats = $statisticsService->getStats();
        $output->writeln('');
        $output->writeln('<info>=== Processing Statistics ===</info>');
        $output->writeln(\sprintf('Total files: <info>%d</info>', $allStats['total']));
        $output->writeln(\sprintf('Processed: <info>%d</info>', $allStats['processed']));
        if ($allStats['skipped'] > 0) {
            $output->writeln(\sprintf('Skipped: <comment>%d</comment>', $allStats['skipped']));
        }
        if ($allStats['errors'] > 0) {
            $output->writeln(\sprintf('Errors: <error>%d</error>', $allStats['errors']));
        }
    }

    // Display skipped files statistics
    if ($skippedStats['total'] > 0) {
        $output->writeln('');
        $output->writeln('<info>=== Skipped Files Details ===</info>');
        $output->writeln(\sprintf(
            'Total skipped: <comment>%d</comment>',
            $skippedStats['total']
        ));
        if ($skippedStats['duplicates'] > 0) {
            $output->writeln(\sprintf(
                '  - Duplicates (same content, different path): <comment>%d</comment>',
                $skippedStats['duplicates']
            ));
        }
        if ($skippedStats['already_processed'] > 0) {
            $output->writeln(\sprintf(
                '  - Already processed: <comment>%d</comment>',
                $skippedStats['already_processed']
            ));
        }
    } elseif (null !== $statisticsService) {
        // Show that no files were skipped
        $output->writeln('');
        $output->writeln('<info>No files were skipped</info>');
    }

    if ($finalDuplicateStats['duplicate_files'] > 0) {
        $output->writeln(\sprintf(
            'Duplicate files skipped: <comment>%d</comment> (<comment>%s</comment>)',
            $finalDuplicateStats['duplicate_files'],
            $byteFormatter->format($finalDuplicateStats['duplicate_size'])
        ));
    }
    $output->writeln('');

    // Log detailed information to file
    $logger->debug('Size moved to destination: '.$byteFormatter->format($movedSize));
    $logger->debug('Size remaining in source: '.$byteFormatter->format($remainingSize));
    if ($finalDuplicateStats['duplicate_files'] > 0) {
        $logger->debug('Duplicate files statistics', [
            'duplicate_count' => $finalDuplicateStats['duplicate_files'],
            'duplicate_size' => $finalDuplicateStats['duplicate_size'],
            'total_files' => $finalDuplicateStats['total_files'],
            'unique_files' => $finalDuplicateStats['unique_files'],
        ]);
    }

    // Safety checks
    $warnings = [];
    $errors = [];

    // Check 1: Verify total size matches (accounting for duplicates)
    $totalSizeAfter = $destinationDirectorySizeAfter + $sourceDirectorySizeAfter;
    $totalSizeBefore = $sourceDirectorySize + $destinationDirectorySizeBefore;
    // Note: duplicates are not copied, so they don't affect the total size calculation

    if ($totalSizeAfter !== $totalSizeBefore) {
        $difference = abs($totalSizeAfter - $totalSizeBefore);
        $errorMsg = \sprintf(
            'ALERT!!! Total size mismatch: Expected %s, Got %s (Difference: %s)',
            $byteFormatter->format($totalSizeBefore),
            $byteFormatter->format($totalSizeAfter),
            $byteFormatter->format($difference)
        );
        $errors[] = $errorMsg;
        $output->writeln(\sprintf('<error>%s</error>', $errorMsg));
    }

    // Check 2: Verify expected final size (accounting for duplicates)
    // Allow small difference due to rounding or file system differences
    $sizeDifference = abs($destinationDirectorySizeAfter - $expectedFinalSize);
    $sizeDifferencePercent = $expectedFinalSize > 0 ? ($sizeDifference / $expectedFinalSize) * 100 : 0;

    if ($sizeDifferencePercent > 0.1) { // More than 0.1% difference
        $warningMsg = \sprintf(
            'Destination size differs from expected: Expected %s, Got %s (Difference: %s, %.2f%%)',
            $byteFormatter->format($expectedFinalSize),
            $byteFormatter->format($destinationDirectorySizeAfter),
            $byteFormatter->format($sizeDifference),
            $sizeDifferencePercent
        );
        $warnings[] = $warningMsg;
        $output->writeln(\sprintf('<comment>Warning: %s</comment>', $warningMsg));
    }

    // Check 3: Verify files were actually moved (if not dry-run)
    if (!$dryRun && 0 === $movedSize && $sourceDirectorySize > 0) {
        $warningMsg = 'No files were moved, but source directory is not empty';
        $warnings[] = $warningMsg;
        $output->writeln(\sprintf('<comment>Warning: %s</comment>', $warningMsg));
    }

    // Log warnings and errors to file
    foreach ($warnings as $warning) {
        $logger->warning($warning);
    }

    foreach ($errors as $error) {
        $logger->error($error);
    }

    if (empty($errors) && empty($warnings)) {
        $output->writeln('<info>✅ All integrity checks passed!</info>');
        $logger->info('✅ All integrity checks passed!');
    }

    $output->writeln('');
    $output->writeln('<info>=== File Sorting Application Completed ===</info>');
    $logger->info('=== File Sorting Application Completed ===');

    exit(empty($errors) ? 0 : 1);
} catch (Throwable $throwable) {
    if (isset($logger)) {
        $logger->error($throwable::class.': '.$throwable->getMessage(), [
            'exception' => $throwable,
            'trace' => $throwable->getTraceAsString(),
        ]);
    } else {
        echo \sprintf("[%s] %s\n", $throwable::class, $throwable->getMessage());
        echo $throwable->getTraceAsString()."\n";
    }

    exit(1);
}
