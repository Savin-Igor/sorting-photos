<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use Doctrine\DBAL\Connection;
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
        $sizeStats = $this->getSizeStatistics();
        $fileTypeStats = $this->getFileTypeStatistics();
        $batchStats = $this->getBatchStatistics();

        // Display statistics
        $io->title('Upload Statistics');
        $io->table(
            ['State', 'Count', 'Percentage', 'Total Size'],
            [
                ['Pending', $stats['pending'], $this->formatPercentage($stats['pending'], $stats['total']), $this->formatBytes($sizeStats['pending'])],
                ['Uploading', $stats['uploading'], $this->formatPercentage($stats['uploading'], $stats['total']), $this->formatBytes($sizeStats['uploading'])],
                ['Uploaded', $stats['uploaded'], $this->formatPercentage($stats['uploaded'], $stats['total']), $this->formatBytes($sizeStats['uploaded'])],
                ['In Batch', $stats['in_batch'], $this->formatPercentage($stats['in_batch'], $stats['total']), $this->formatBytes($sizeStats['in_batch'])],
                ['Completed', $stats['completed'], $this->formatPercentage($stats['completed'], $stats['total']), $this->formatBytes($sizeStats['completed'])],
                ['Paused', $stats['paused'], $this->formatPercentage($stats['paused'], $stats['total']), $this->formatBytes($sizeStats['paused'])],
                ['Failed', $stats['failed'], $this->formatPercentage($stats['failed'], $stats['total']), $this->formatBytes($sizeStats['failed'])],
                ['<fg=red>Not Found</>', '<fg=red>'.$stats['not_found'].'</>', '<fg=red>'.$this->formatPercentage($stats['not_found'], $stats['total']).'</>', '<fg=red>'.$this->formatBytes($sizeStats['not_found']).'</>'],
                ['<fg=gray>Archived</>', '<fg=gray>'.$stats['archived'].'</>', '<fg=gray>'.$this->formatPercentage($stats['archived'], $stats['total']).'</>', '<fg=gray>'.$this->formatBytes($sizeStats['archived']).'</>'],
                ['---', '---', '---', '---'],
                ['<fg=cyan>Total</>', '<fg=cyan>'.$stats['total'].'</>', '<fg=cyan>100%</>', '<fg=cyan>'.$this->formatBytes($sizeStats['total']).'</>'],
            ]
        );

        // Display file type statistics
        if ($fileTypeStats['total'] > 0) {
            $io->section('File Type Statistics');
            $io->table(
                ['Type', 'Count', 'Percentage', 'Total Size'],
                [
                    ['Images', $fileTypeStats['images'], $this->formatPercentage($fileTypeStats['images'], $fileTypeStats['total']), $this->formatBytes($fileTypeStats['images_size'])],
                    ['Videos', $fileTypeStats['videos'], $this->formatPercentage($fileTypeStats['videos'], $fileTypeStats['total']), $this->formatBytes($fileTypeStats['videos_size'])],
                    ['---', '---', '---', '---'],
                    ['<fg=cyan>Total</>', '<fg=cyan>'.$fileTypeStats['total'].'</>', '<fg=cyan>100%</>', '<fg=cyan>'.$this->formatBytes($fileTypeStats['total_size']).'</>'],
                ]
            );
        }

        // Display batch statistics
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

        // Display progress summary
        // Exclude archived and not_found files from active processing
        $activeTotal = $stats['total'] - $stats['archived'] - $stats['not_found'];
        $activeCompleted = $stats['completed'];
        $progressPercent = $activeTotal > 0
            ? \round(($activeCompleted / $activeTotal) * 100, 1)
            : 0;
        $io->section('Overall Progress');
        $io->writeln(\sprintf('Completed: <fg=green>%s</> / %s (<fg=green>%s%%</>)', $activeCompleted, $activeTotal, $progressPercent));
        $io->writeln(\sprintf('Remaining: <fg=yellow>%s</> files (<fg=yellow>%s</>)', $activeTotal - $activeCompleted, $this->formatBytes($sizeStats['total'] - $sizeStats['completed'] - $sizeStats['archived'] - $sizeStats['not_found'])));
        if ($stats['not_found'] > 0) {
            $io->writeln(\sprintf('<fg=red>Not Found (excluded): %s files (%s)</>', $stats['not_found'], $this->formatBytes($sizeStats['not_found'])));
        }
        if ($stats['archived'] > 0) {
            $io->writeln(\sprintf('<fg=gray>Archived (excluded): %s files (%s)</>', $stats['archived'], $this->formatBytes($sizeStats['archived'])));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{pending: int, uploading: int, uploaded: int, in_batch: int, completed: int, paused: int, failed: int, archived: int, not_found: int, total: int}
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
            'archived' => 0,
            'not_found' => 0,
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

    /**
     * @return array{pending: int, uploading: int, uploaded: int, in_batch: int, completed: int, paused: int, failed: int, archived: int, not_found: int, total: int}
     */
    private function getSizeStatistics(): array
    {
        $result = $this->connection->fetchAllAssociative(
            'SELECT state, COALESCE(SUM(file_size), 0) as total_size FROM google_photos_upload_jobs GROUP BY state'
        );

        $stats = [
            'pending' => 0,
            'uploading' => 0,
            'uploaded' => 0,
            'in_batch' => 0,
            'completed' => 0,
            'paused' => 0,
            'failed' => 0,
            'archived' => 0,
            'not_found' => 0,
            'total' => 0,
        ];

        foreach ($result as $row) {
            $state = \is_string($row['state']) ? $row['state'] : '';
            $size = (int) ($row['total_size'] ?? 0);
            if (isset($stats[$state])) {
                $stats[$state] = $size;
                $stats['total'] += $size;
            }
        }

        return $stats;
    }

    /**
     * @return array{images: int, videos: int, images_size: int, videos_size: int, total: int, total_size: int}
     */
    private function getFileTypeStatistics(): array
    {
        $result = $this->connection->fetchAssociative(
            'SELECT 
                SUM(CASE WHEN is_video = 0 THEN 1 ELSE 0 END) as images_count,
                SUM(CASE WHEN is_video = 1 THEN 1 ELSE 0 END) as videos_count,
                COALESCE(SUM(CASE WHEN is_video = 0 THEN file_size ELSE 0 END), 0) as images_size,
                COALESCE(SUM(CASE WHEN is_video = 1 THEN file_size ELSE 0 END), 0) as videos_size,
                COUNT(*) as total_count,
                COALESCE(SUM(file_size), 0) as total_size
             FROM google_photos_upload_jobs'
        );

        if (false === $result) {
            return [
                'images' => 0,
                'videos' => 0,
                'images_size' => 0,
                'videos_size' => 0,
                'total' => 0,
                'total_size' => 0,
            ];
        }

        return [
            'images' => isset($result['images_count']) && \is_numeric($result['images_count']) ? (int) $result['images_count'] : 0,
            'videos' => isset($result['videos_count']) && \is_numeric($result['videos_count']) ? (int) $result['videos_count'] : 0,
            'images_size' => isset($result['images_size']) && \is_numeric($result['images_size']) ? (int) $result['images_size'] : 0,
            'videos_size' => isset($result['videos_size']) && \is_numeric($result['videos_size']) ? (int) $result['videos_size'] : 0,
            'total' => isset($result['total_count']) && \is_numeric($result['total_count']) ? (int) $result['total_count'] : 0,
            'total_size' => isset($result['total_size']) && \is_numeric($result['total_size']) ? (int) $result['total_size'] : 0,
        ];
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
            $state = \is_string($row['state']) ? $row['state'] : '';
            $count = isset($row['count']) && \is_numeric($row['count']) ? (int) $row['count'] : 0;
            if (isset($stats[$state])) {
                $stats[$state] = $count;
                $stats['total'] += $count;
            }
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
