<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service;

use SortingPhotosByDate\Domain\Event\FileDiscovered;
use SortingPhotosByDate\Infrastructure\Helper\DirectorySizeCalculator;
use SortingPhotosByDate\Infrastructure\Statistics\RedisStatisticsService;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\MetadataRepositoryPort;
use SortingPhotosByDate\Ports\ScannerPort;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Orchestrator service for file organizing process.
 * Handles scanning, processing, and provides statistics.
 */
final readonly class FileOrganizingService
{
    public function __construct(
        private ScannerPort $scanner,
        private MessageBusInterface $messageBus,
        private FilesystemPort $filesystem,
        private LoggerPort $logger,
        private MetadataRepositoryPort $repository,
        private DirectorySizeCalculator $sizeCalculator,
        private ?RedisStatisticsService $statistics = null,
    ) {
    }

    /**
     * Organize files from source to destination directory.
     *
     * @param string $sourceDirectory      Source directory path
     * @param string $destinationDirectory Destination directory path
     * @param bool   $dryRun               Run in dry-run mode (no actual changes)
     *
     * @return array{
     *     total_files: int,
     *     discovered: int,
     *     skipped: int,
     *     source_size_before: int,
     *     destination_size_before: int,
     *     source_size_after: int,
     *     destination_size_after: int,
     *     duplicate_size: int,
     *     duplicate_files: int
     * }
     */
    public function organize(string $sourceDirectory, string $destinationDirectory, bool $dryRun = false): array
    {
        // Calculate sizes before processing
        $sourceSizeBefore = $this->sizeCalculator->calculate($sourceDirectory);
        $destinationSizeBefore = $this->sizeCalculator->calculate($destinationDirectory);

        // Get duplicate statistics
        $duplicateStats = $this->repository->getDuplicateStats();
        $duplicateSize = $duplicateStats['duplicate_size'] ?? 0;
        $duplicateFiles = $duplicateStats['duplicate_files'] ?? 0;

        // Count total files
        $totalFiles = $this->scanner->count($sourceDirectory);

        // Set total in Redis for statistics tracking
        if ($this->statistics instanceof RedisStatisticsService) {
            $this->statistics->setTotal($totalFiles);
        }

        if (0 === $totalFiles) {
            $this->logger->info('No files found in source directory', [
                'source_directory' => $sourceDirectory,
            ]);

            return [
                'total_files' => 0,
                'discovered' => 0,
                'skipped' => 0,
                'source_size_before' => $sourceSizeBefore,
                'destination_size_before' => $destinationSizeBefore,
                'source_size_after' => $sourceSizeBefore,
                'destination_size_after' => $destinationSizeBefore,
                'duplicate_size' => $duplicateSize,
                'duplicate_files' => $duplicateFiles,
            ];
        }

        // Scan files and dispatch events
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

        $this->logger->info('File scan completed', [
            'source_directory' => $sourceDirectory,
            'total_files' => $fileCount,
            'discovered' => $discoveredCount,
            'skipped' => $skippedCount,
            'dry_run' => $dryRun,
        ]);

        // Calculate sizes after processing
        $sourceSizeAfter = $this->sizeCalculator->calculate($sourceDirectory);
        $destinationSizeAfter = $this->sizeCalculator->calculate($destinationDirectory);

        // Get updated duplicate statistics
        $finalDuplicateStats = $this->repository->getDuplicateStats();

        return [
            'total_files' => $fileCount,
            'discovered' => $discoveredCount,
            'skipped' => $skippedCount,
            'source_size_before' => $sourceSizeBefore,
            'destination_size_before' => $destinationSizeBefore,
            'source_size_after' => $sourceSizeAfter,
            'destination_size_after' => $destinationSizeAfter,
            'duplicate_size' => $finalDuplicateStats['duplicate_size'] ?? 0,
            'duplicate_files' => $finalDuplicateStats['duplicate_files'] ?? 0,
        ];
    }
}
