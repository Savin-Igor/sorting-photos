<?php

declare(strict_types=1);

use SortingPhotosByDate\Command\ScanFilesCommand;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
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

/**
 * Recursively calculates the size of a directory.
 */
function getDirectorySize(string $directory, FilesystemPort $filesystem): int
{
    if (!is_dir($directory)) {
        return 0;
    }

    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            try {
                $filePath = new FilePath($file->getRealPath() ?: $file->getPathname());
                $size += $filesystem->getSize($filePath);
            } catch (Exception) {
                // Skip files that cannot be read
                continue;
            }
        }
    }

    return $size;
}

/**
 * Formats the size in bytes in a readable form (KB, MB, GB, etc.).
 */
function formatBytes(int $size): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;

    while ($size >= 1024 && $i < 4) {
        $size /= 1024;
        ++$i;
    }

    return round($size, 2).' '.$units[$i];
}

try {
    // Load DI container
    $container = new ContainerBuilder();
    $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/config'));
    $loader->load('services.yaml');

    // Load flysystem configuration
    if (file_exists(__DIR__.'/config/packages/flysystem.yaml')) {
        $loader->load('packages/flysystem.yaml');
    }

    // Load messenger configuration if needed
    // Note: For index.php, we'll use sync transport for immediate processing

    // Set kernel.project_dir parameter
    if (!$container->hasParameter('kernel.project_dir')) {
        $container->setParameter('kernel.project_dir', __DIR__);
    }

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

    // Get configuration from environment BEFORE compiling container
    $sourceDirectory = $_ENV['SOURCE_DIRECTORY'] ?? getenv('SOURCE_DIRECTORY') ?: '';
    $destinationDirectory = $_ENV['DESTINATION_DIRECTORY'] ?? getenv('DESTINATION_DIRECTORY') ?: '';
    $organizerPolicy = $_ENV['ORGANIZER_POLICY'] ?? getenv('ORGANIZER_POLICY') ?: 'date-type';
    $dryRun = filter_var($_ENV['DRY_RUN'] ?? getenv('DRY_RUN') ?: 'false', FILTER_VALIDATE_BOOLEAN);

    if (empty($sourceDirectory) || empty($destinationDirectory)) {
        throw new RuntimeException('SOURCE_DIRECTORY and DESTINATION_DIRECTORY must be set in .env file or environment variables');
    }

    // Update destination directory parameter before compiling
    $container->setParameter('app.destination_directory', $destinationDirectory);

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

    // Get services after compilation
    $logger = $container->get(LoggerPort::class);
    $filesystem = $container->get(FilesystemPort::class);
    $scanner = $container->get(ScannerPort::class);
    $messageBus = $container->get(MessageBusInterface::class);

    // Validate directories
    if (!is_dir($sourceDirectory)) {
        throw new RuntimeException("Source directory does not exist: {$sourceDirectory}");
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
    $sourceDirectorySize = getDirectorySize($sourceDirectory, $filesystem);
    $destinationDirectorySizeBefore = getDirectorySize($destinationDirectory, $filesystem);
    $expectedFinalSize = $sourceDirectorySize + $destinationDirectorySizeBefore;

    $logger->info('Source Directory Size: '.formatBytes($sourceDirectorySize));
    $logger->info('Destination Directory Size Before: '.formatBytes($destinationDirectorySizeBefore));
    $logger->info('Expected Final Size: '.formatBytes($expectedFinalSize));

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
    $destinationDirectorySizeAfter = getDirectorySize($destinationDirectory, $filesystem);
    $sourceDirectorySizeAfter = getDirectorySize($sourceDirectory, $filesystem);

    $logger->info('Destination Directory Size After: '.formatBytes($destinationDirectorySizeAfter));
    $logger->info('Source Directory Size After: '.formatBytes($sourceDirectorySizeAfter));

    // Verify integrity
    $movedSize = $destinationDirectorySizeAfter - $destinationDirectorySizeBefore;
    $remainingSize = $sourceDirectorySizeAfter;

    $logger->info('Size moved to destination: '.formatBytes($movedSize));
    $logger->info('Size remaining in source: '.formatBytes($remainingSize));

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
            formatBytes($totalSizeBefore),
            formatBytes($totalSizeAfter),
            formatBytes($difference)
        );
    }

    // Check 2: Verify expected final size
    if ($destinationDirectorySizeAfter !== $expectedFinalSize) {
        $difference = abs($destinationDirectorySizeAfter - $expectedFinalSize);
        $warnings[] = \sprintf(
            'Destination size does not match expected: Expected %s, Got %s (Difference: %s)',
            formatBytes($expectedFinalSize),
            formatBytes($destinationDirectorySizeAfter),
            formatBytes($difference)
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
