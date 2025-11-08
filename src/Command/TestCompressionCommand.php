<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\ImageCompressor;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\VideoCompressor;
use SortingPhotosByDate\Ports\LoggerPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mime\MimeTypes;

#[AsCommand(
    name: 'test:compression',
    description: 'Test file compression by copying files from source to destination with optional compression'
)]
final class TestCompressionCommand extends Command
{
    public function __construct(
        private readonly ImageCompressor $imageCompressor,
        private readonly VideoCompressor $videoCompressor,
        private readonly LoggerPort $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'Source directory path (uses SOURCE_DIRECTORY_HOST from .env if not provided)')
            ->addArgument('destination', InputArgument::OPTIONAL, 'Destination directory path (uses DESTINATION_DIRECTORY_HOST from .env if not provided)')
            ->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Process files recursively')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without actually copying');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // Get source directory from argument or environment variable
        $sourceDir = $input->getArgument('source');
        if (null === $sourceDir || '' === $sourceDir) {
            $sourceDir = getenv('SOURCE_DIRECTORY_HOST') ?: ($_ENV['SOURCE_DIRECTORY_HOST'] ?? '');
            if ('' === $sourceDir) {
                $io->error('Source directory is required. Provide it as argument or set SOURCE_DIRECTORY_HOST in .env file.');
                return Command::FAILURE;
            }
        }
        
        // Get destination directory from argument or environment variable
        $destinationDir = $input->getArgument('destination');
        if (null === $destinationDir || '' === $destinationDir) {
            $destinationDir = getenv('DESTINATION_DIRECTORY_HOST') ?: ($_ENV['DESTINATION_DIRECTORY_HOST'] ?? '');
            if ('' === $destinationDir) {
                $io->error('Destination directory is required. Provide it as argument or set DESTINATION_DIRECTORY_HOST in .env file.');
                return Command::FAILURE;
            }
        }
        
        $recursive = $input->getOption('recursive');
        $dryRun = $input->getOption('dry-run');

        $io->title('File Compression Test');

        // Validate directories
        if (!\is_dir($sourceDir)) {
            $io->error(\sprintf('Source directory does not exist: %s', $sourceDir));

            return Command::FAILURE;
        }

        if (!$dryRun && !\is_dir($destinationDir)) {
            if (!\mkdir($destinationDir, 0755, true)) {
                $io->error(\sprintf('Failed to create destination directory: %s', $destinationDir));

                return Command::FAILURE;
            }
        }

        $io->section('Configuration');
        $io->writeln([
            \sprintf('Source: %s', $sourceDir),
            \sprintf('Destination: %s', $destinationDir),
            \sprintf('Recursive: %s', $recursive ? 'Yes' : 'No'),
            \sprintf('Dry run: %s', $dryRun ? 'Yes' : 'No'),
        ]);

        // Find files
        $files = $this->findFiles($sourceDir, $recursive);
        $io->section(\sprintf('Found %d file(s)', \count($files)));

        if (0 === \count($files)) {
            $io->warning('No files found in source directory');

            return Command::SUCCESS;
        }

        // Process files
        $stats = [
            'total' => \count($files),
            'processed' => 0,
            'compressed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'original_size' => 0,
            'compressed_size' => 0,
        ];

        $mimeTypes = new MimeTypes();

        foreach ($files as $file) {
            try {
                $relativePath = \str_replace($sourceDir.\DIRECTORY_SEPARATOR, '', $file);
                $destinationPath = $destinationDir.\DIRECTORY_SEPARATOR.$relativePath;
                $destinationDirPath = \dirname($destinationPath);

                if (!$dryRun && !\is_dir($destinationDirPath)) {
                    \mkdir($destinationDirPath, 0755, true);
                }

                $originalSize = \filesize($file);
                if (false === $originalSize) {
                    throw new \RuntimeException('Failed to get file size');
                }
                $stats['original_size'] += $originalSize;

                $mimeType = $mimeTypes->guessMimeType($file) ?? 'application/octet-stream';
                $compressedPath = $this->compressFile($file, $mimeType);

                if ($compressedPath !== $file) {
                    $stats['compressed']++;
                    $compressedSize = \filesize($compressedPath);
                    if (false === $compressedSize) {
                        throw new \RuntimeException('Failed to get compressed file size');
                    }
                    $stats['compressed_size'] += $compressedSize;

                    $io->writeln([
                        \sprintf('<fg=cyan>%s</>', $relativePath),
                        \sprintf('  Original: %s', $this->formatBytes($originalSize)),
                        \sprintf('  Compressed: %s', $this->formatBytes($compressedSize)),
                        \sprintf('  Saved: %s (%.1f%%)', $this->formatBytes($originalSize - $compressedSize), (($originalSize - $compressedSize) / $originalSize) * 100),
                    ]);

                    if (!$dryRun) {
                        \copy($compressedPath, $destinationPath);
                        // Clean up temp file if it was created
                        if (\str_starts_with($compressedPath, \sys_get_temp_dir())) {
                            \unlink($compressedPath);
                        }
                    }
                } else {
                    $stats['skipped']++;
                    $stats['compressed_size'] += $originalSize;

                    if (!$dryRun) {
                        \copy($file, $destinationPath);
                    }
                }

                $stats['processed']++;
            } catch (\Exception $e) {
                $stats['errors']++;
                $io->error([
                    \sprintf('Failed to process %s:', $file),
                    $e->getMessage(),
                ]);
                $this->logger->error('Compression test failed', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Show statistics
        $io->section('Statistics');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total files', (string) $stats['total']],
                ['Processed', (string) $stats['processed']],
                ['Compressed', (string) $stats['compressed']],
                ['Skipped', (string) $stats['skipped']],
                ['Errors', (string) $stats['errors']],
                ['Original size', $this->formatBytes($stats['original_size'])],
                ['Compressed size', $this->formatBytes($stats['compressed_size'])],
                ['Total saved', $this->formatBytes($stats['original_size'] - $stats['compressed_size'])],
                ['Compression ratio', \sprintf('%.1f%%', $stats['original_size'] > 0 ? (($stats['original_size'] - $stats['compressed_size']) / $stats['original_size']) * 100 : 0)],
            ]
        );

        if ($dryRun) {
            $io->note('This was a dry run. No files were actually copied.');
        }

        return 0 === $stats['errors'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Find files in directory.
     *
     * @return array<string>
     */
    private function findFiles(string $directory, bool $recursive): array
    {
        $files = [];
        $iterator = $recursive ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)) : new \DirectoryIterator($directory);

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Compress file if needed.
     */
    private function compressFile(string $filePath, string $mimeType): string
    {
        if (\str_starts_with($mimeType, 'image/')) {
            return $this->imageCompressor->compressIfNeeded($filePath, $mimeType);
        }

        if (\str_starts_with($mimeType, 'video/')) {
            return $this->videoCompressor->compressIfNeeded($filePath);
        }

        // Not an image or video, return original
        return $filePath;
    }

    /**
     * Format bytes to human-readable format.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = \max($bytes, 0);
        $pow = \floor(($bytes ? \log($bytes) : 0) / \log(1024));
        $pow = \min($pow, \count($units) - 1);
        $bytes /= 1024 ** $pow;

        return \sprintf('%.2f %s', $bytes, $units[$pow]);
    }
}
