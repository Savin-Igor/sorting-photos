<?php

declare(strict_types=1);

use SortingPhotosByDate\Command\ScanFilesCommand;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Helper\ByteFormatter;
use SortingPhotosByDate\Infrastructure\Helper\DirectorySizeCalculator;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\ScannerPort;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Messenger\MessageBusInterface;

require_once __DIR__.'/vendor/autoload.php';

// Load environment variables
if (file_exists(__DIR__.'/.env')) {
    (new Dotenv())->load(__DIR__.'/.env');
}

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '550M');
ini_set('display_startup_errors', '1');

try {
    // Load DI container
    $container = new ContainerBuilder();

    // Set parameters BEFORE loading YAML files
    $container->setParameter('kernel.project_dir', __DIR__);

    // Get configuration from environment BEFORE loading services
    $sourceDirectory = $_ENV['SOURCE_DIRECTORY'] ?? getenv('SOURCE_DIRECTORY') ?: '';
    $destinationDirectory = $_ENV['DESTINATION_DIRECTORY'] ?? getenv('DESTINATION_DIRECTORY') ?: '';
    $organizerPolicy = $_ENV['ORGANIZER_POLICY'] ?? getenv('ORGANIZER_POLICY') ?: 'date-type';
    $dryRun = filter_var($_ENV['DRY_RUN'] ?? getenv('DRY_RUN') ?: 'false', FILTER_VALIDATE_BOOLEAN);

    if (empty($sourceDirectory) || empty($destinationDirectory)) {
        throw new RuntimeException('SOURCE_DIRECTORY and DESTINATION_DIRECTORY must be set in .env file or environment variables');
    }

    // Set application parameters
    $container->setParameter('app.source_directory', $sourceDirectory);
    $container->setParameter('app.destination_directory', $destinationDirectory);
    $container->setParameter('app.organizer_policy', $organizerPolicy);
    $container->setParameter('app.dry_run', $dryRun);

    // Set FILESYSTEM_DEFAULT_DIR_PERMISSIONS parameter (resolve env var and convert to int)
    $defaultDirPermissions = (int) ($_ENV['FILESYSTEM_DEFAULT_DIR_PERMISSIONS'] ?? getenv('FILESYSTEM_DEFAULT_DIR_PERMISSIONS') ?: '0755');
    $container->setParameter('env(int:FILESYSTEM_DEFAULT_DIR_PERMISSIONS)', $defaultDirPermissions);

    $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/config'));
    $loader->load('services.yaml');

    // Load flysystem configuration
    if (file_exists(__DIR__.'/config/packages/flysystem.yaml')) {
        $loader->load('packages/flysystem.yaml');
    }

    // Load messenger configuration if needed
    // Note: For index.php, we'll use sync transport for immediate processing

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

    // Configure organizer policy based on environment variable
    $policyClass = match ($organizerPolicy) {
        'date-type' => SortingPhotosByDate\Domain\Policies\DateTypePolicy::class,
        'date' => SortingPhotosByDate\Domain\Policies\DatePolicy::class,
        'type-date' => SortingPhotosByDate\Domain\Policies\TypeDatePolicy::class,
        default => SortingPhotosByDate\Domain\Policies\DateTypePolicy::class,
    };

    // Override policy alias before compiling
    $container->setAlias(SortingPhotosByDate\Domain\Policies\OrganizerPolicy::class, $policyClass);

    // Compile container
    $container->compile();

    // Create SynchronousMessageBus manually after compilation to avoid circular dependency
    // (handlers depend on MessageBusInterface, which is SynchronousMessageBus)
    $messageBus = new SortingPhotosByDate\Infrastructure\Messenger\SynchronousMessageBus($container);

    // Set it in container for other services that might need it
    $container->set(MessageBusInterface::class, $messageBus);
    $container->set(SortingPhotosByDate\Infrastructure\Messenger\SynchronousMessageBus::class, $messageBus);

    // Get services after compilation
    $logger = $container->get(LoggerPort::class);
    $filesystem = $container->get(FilesystemPort::class);
    $scanner = $container->get(ScannerPort::class);

    // Create helper instances
    $byteFormatter = new ByteFormatter();
    $directorySizeCalculator = new DirectorySizeCalculator($filesystem);

    // Validate directories
    if (!is_dir($sourceDirectory)) {
        // Try to create source directory if it doesn't exist (for testing)
        if (!mkdir($sourceDirectory, 0755, true)) {
            throw new RuntimeException("Source directory does not exist and could not be created: {$sourceDirectory}");
        }
    }

    // Ensure destination directory exists
    $destinationPath = new FilePath($destinationDirectory);
    $filesystem->ensureDirectory($destinationPath);

    $logger->info('=== File Sorting Application Started ===');
    $logger->info('Source Directory: '.$sourceDirectory);
    $logger->info('Destination Directory: '.$destinationDirectory);
    $logger->info('Dry Run Mode: '.($dryRun ? 'YES' : 'NO'));

    // Calculate directory sizes before processing
    $logger->info('Calculating directory sizes...');
    $sourceDirectorySize = $directorySizeCalculator->calculate($sourceDirectory);
    $destinationDirectorySizeBefore = $directorySizeCalculator->calculate($destinationDirectory);
    $expectedFinalSize = $sourceDirectorySize + $destinationDirectorySizeBefore;

    $logger->info('Source Directory Size: '.$byteFormatter->format($sourceDirectorySize));
    $logger->info('Destination Directory Size Before: '.$byteFormatter->format($destinationDirectorySizeBefore));
    $logger->info('Expected Final Size: '.$byteFormatter->format($expectedFinalSize));

    // Create and execute ScanFilesCommand
    $scanCommand = new ScanFilesCommand($scanner, $messageBus, $filesystem, $logger);
    $input = new ArrayInput([
        'source-directory' => $sourceDirectory,
        '--dry-run' => $dryRun,
    ]);
    $output = new BufferedOutput();

    $logger->info('Starting file scan and processing...');
    $exitCode = $scanCommand->run($input, $output);
    $commandOutput = $output->fetch();

    if (0 !== $exitCode) {
        $logger->error('Scan command failed with exit code: '.$exitCode);
        $logger->error('Command output: '.$commandOutput);
        throw new RuntimeException('File scan failed');
    }

    $logger->info('Scan command completed successfully');
    if (!empty($commandOutput)) {
        $logger->info('Command output: '.trim($commandOutput));
    }

    // For synchronous processing, we need to consume messages immediately
    // In production, this would be handled by workers, but for index.php we process synchronously
    if (!$dryRun) {
        $logger->info('Processing messages synchronously...');
        // Note: In a real scenario, you would run messenger:consume here
        // For now, we'll rely on the handlers being called synchronously if using sync transport
    }

    // Calculate directory sizes after processing
    $logger->info('Calculating directory sizes after processing...');
    $destinationDirectorySizeAfter = $directorySizeCalculator->calculate($destinationDirectory);
    $sourceDirectorySizeAfter = $directorySizeCalculator->calculate($sourceDirectory);

    $logger->info('Destination Directory Size After: '.$byteFormatter->format($destinationDirectorySizeAfter));
    $logger->info('Source Directory Size After: '.$byteFormatter->format($sourceDirectorySizeAfter));

    // Verify integrity
    $movedSize = $destinationDirectorySizeAfter - $destinationDirectorySizeBefore;
    $remainingSize = $sourceDirectorySizeAfter;

    $logger->info('Size moved to destination: '.$byteFormatter->format($movedSize));
    $logger->info('Size remaining in source: '.$byteFormatter->format($remainingSize));

    // Safety checks
    $warnings = [];
    $errors = [];

    // Check 1: Verify total size matches
    $totalSizeAfter = $destinationDirectorySizeAfter + $sourceDirectorySizeAfter;
    $totalSizeBefore = $sourceDirectorySize + $destinationDirectorySizeBefore;

    if ($totalSizeAfter !== $totalSizeBefore) {
        $difference = abs($totalSizeAfter - $totalSizeBefore);
        $errors[] = \sprintf(
            'ALERT!!! Total size mismatch: Expected %s, Got %s (Difference: %s)',
            $byteFormatter->format($totalSizeBefore),
            $byteFormatter->format($totalSizeAfter),
            $byteFormatter->format($difference)
        );
    }

    // Check 2: Verify expected final size
    if ($destinationDirectorySizeAfter !== $expectedFinalSize) {
        $difference = abs($destinationDirectorySizeAfter - $expectedFinalSize);
        $warnings[] = \sprintf(
            'Destination size does not match expected: Expected %s, Got %s (Difference: %s)',
            $byteFormatter->format($expectedFinalSize),
            $byteFormatter->format($destinationDirectorySizeAfter),
            $byteFormatter->format($difference)
        );
    }

    // Check 3: Verify files were actually moved (if not dry-run)
    if (!$dryRun && 0 === $movedSize && $sourceDirectorySize > 0) {
        $warnings[] = 'No files were moved, but source directory is not empty';
    }

    // Log warnings and errors
    foreach ($warnings as $warning) {
        $logger->warning($warning);
    }

    foreach ($errors as $error) {
        $logger->error($error);
    }

    if (empty($errors) && empty($warnings)) {
        $logger->info('✅ All integrity checks passed!');
    }

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
