<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use Doctrine\DBAL\Connection;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google-photos:status',
    description: 'Show upload status and statistics'
)]
final class GooglePhotosStatusCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $stats = $this->getStatistics();
        $recentJobs = $this->getRecentJobs(10);

        // Display statistics
        $io->title('Upload Statistics');
        $io->table(
            ['State', 'Count', 'Percentage'],
            [
                ['Pending', $stats['pending'], $this->formatPercentage($stats['pending'], $stats['total'])],
                ['Uploading', $stats['uploading'], $this->formatPercentage($stats['uploading'], $stats['total'])],
                ['Uploaded', $stats['uploaded'], $this->formatPercentage($stats['uploaded'], $stats['total'])],
                ['In Batch', $stats['in_batch'], $this->formatPercentage($stats['in_batch'], $stats['total'])],
                ['Completed', $stats['completed'], $this->formatPercentage($stats['completed'], $stats['total'])],
                ['Paused', $stats['paused'], $this->formatPercentage($stats['paused'], $stats['total'])],
                ['Failed', $stats['failed'], $this->formatPercentage($stats['failed'], $stats['total'])],
                ['---', '---', '---'],
                ['<fg=cyan>Total</>', '<fg=cyan>'.$stats['total'].'</>', '<fg=cyan>100%</>'],
            ]
        );

        // Display progress for uploading files
        $uploadingJobs = $this->getUploadingJobs();
        if ([] !== $uploadingJobs) {
            $io->section('Currently Uploading');
            $rows = [];
            foreach ($uploadingJobs as $job) {
                $progress = $job['file_size'] > 0
                    ? \round(($job['uploaded_bytes'] / $job['file_size']) * 100, 1)
                    : 0;
                $rows[] = [
                    \basename($job['file_path']),
                    $this->formatBytes($job['uploaded_bytes']).' / '.$this->formatBytes($job['file_size']),
                    $progress.'%',
                    $job['state'],
                ];
            }
            $io->table(['File', 'Progress', 'Percentage', 'State'], $rows);
        }

        // Display recent jobs
        if ([] !== $recentJobs) {
            $io->section('Recent Jobs (Last 10)');
            $rows = [];
            foreach ($recentJobs as $job) {
                $rows[] = [
                    \basename($job['file_path']),
                    $this->formatBytes($job['file_size']),
                    $job['state'],
                    $job['updated_at'],
                ];
            }
            $io->table(['File', 'Size', 'State', 'Last Updated'], $rows);
        }

        // Display batch statistics
        $batchStats = $this->getBatchStatistics();
        if ($batchStats['total'] > 0) {
            $io->section('Batch Statistics');
            $io->table(
                ['State', 'Count'],
                [
                    ['Ready', $batchStats['ready']],
                    ['Processing', $batchStats['processing']],
                    ['Completed', $batchStats['completed']],
                    ['Paused', $batchStats['paused']],
                    ['Failed', $batchStats['failed']],
                    ['---', '---'],
                    ['<fg=cyan>Total</>', '<fg=cyan>'.$batchStats['total'].'</>'],
                ]
            );
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{pending: int, uploading: int, uploaded: int, in_batch: int, completed: int, paused: int, failed: int, total: int}
     */
    private function getStatistics(): array
    {
        $result = $this->connection->fetchAllAssociative(
            'SELECT state, COUNT(*) as count FROM google_photos_upload_jobs GROUP BY state'
        );

        $stats = [
            'pending' => 0,
            'uploading' => 0,
            'uploaded' => 0,
            'in_batch' => 0,
            'completed' => 0,
            'paused' => 0,
            'failed' => 0,
            'total' => 0,
        ];

        foreach ($result as $row) {
            $state = $row['state'];
            $count = (int) $row['count'];
            $stats[$state] = $count;
            $stats['total'] += $count;
        }

        return $stats;
    }

    /**
     * @return array<int, array{file_path: string, file_size: int, uploaded_bytes: int, state: string}>
     */
    private function getUploadingJobs(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT file_path, file_size, uploaded_bytes, state
             FROM google_photos_upload_jobs
             WHERE state IN (?, ?)
             ORDER BY updated_at DESC
             LIMIT 10',
            [UploadState::PENDING->value, UploadState::UPLOADING->value]
        );
    }

    /**
     * @return array<int, array{file_path: string, file_size: int, state: string, updated_at: string}>
     */
    private function getRecentJobs(int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT file_path, file_size, state, updated_at
             FROM google_photos_upload_jobs
             ORDER BY updated_at DESC
             LIMIT ?',
            [$limit]
        );
    }

    /**
     * @return array{ready: int, processing: int, completed: int, paused: int, failed: int, total: int}
     */
    private function getBatchStatistics(): array
    {
        $result = $this->connection->fetchAllAssociative(
            'SELECT state, COUNT(*) as count FROM google_photos_upload_batches GROUP BY state'
        );

        $stats = [
            'ready' => 0,
            'processing' => 0,
            'completed' => 0,
            'paused' => 0,
            'failed' => 0,
            'total' => 0,
        ];

        foreach ($result as $row) {
            $state = $row['state'];
            $count = (int) $row['count'];
            if (isset($stats[$state])) {
                $stats[$state] = $count;
            }
            $stats['total'] += $count;
        }

        return $stats;
    }

    private function formatPercentage(int $value, int $total): string
    {
        if (0 === $total) {
            return '0%';
        }

        return \round(($value / $total) * 100, 1).'%';
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = \max($bytes, 0);
        $pow = \floor(($bytes ? \log($bytes) : 0) / \log(1024));
        $pow = \min($pow, \count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return \round($bytes, 2).' '.$units[$pow];
    }
}
