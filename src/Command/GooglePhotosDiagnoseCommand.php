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
    name: 'google-photos:diagnose',
    description: 'Diagnose upload job and batch states'
)]
final class GooglePhotosDiagnoseCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Upload Jobs Diagnosis');

        // Check UPLOADED files with and without batch_id
        $uploadedStats = $this->connection->fetchAllAssociative(
            'SELECT 
                CASE WHEN batch_id IS NULL THEN "no_batch" ELSE "has_batch" END as batch_status,
                COUNT(*) as count,
                SUM(file_size) as total_size
             FROM google_photos_upload_jobs 
             WHERE state = ?
             GROUP BY batch_status',
            [UploadState::UPLOADED->value]
        );

        $io->section('UPLOADED Files Breakdown');
        if ([] !== $uploadedStats) {
            $io->table(
                ['Batch Status', 'Count', 'Total Size'],
                \array_map(
                    fn (array $row): array => [
                        \is_string($row['batch_status'] ?? null) ? $row['batch_status'] : '',
                        \number_format(\is_numeric($row['count'] ?? null) ? (int) $row['count'] : 0),
                        $this->formatBytes(\is_numeric($row['total_size'] ?? null) ? (int) $row['total_size'] : 0),
                    ],
                    $uploadedStats
                )
            );
        }

        // Check UPLOADED files with batch_id and their batch states
        $uploadedWithBatch = $this->connection->fetchAllAssociative(
            'SELECT 
                COALESCE(b.state, "NULL") as batch_state,
                COUNT(*) as count,
                SUM(j.file_size) as total_size
             FROM google_photos_upload_jobs j
             LEFT JOIN google_photos_upload_batches b ON j.batch_id = b.id
             WHERE j.state = ? AND j.batch_id IS NOT NULL
             GROUP BY b.state',
            [UploadState::UPLOADED->value]
        );

        if ([] !== $uploadedWithBatch) {
            $io->section('UPLOADED Files with Batch ID');
            $io->table(
                ['Batch State', 'Count', 'Total Size'],
                \array_map(
                    fn (array $row): array => [
                        \is_string($row['batch_state'] ?? null) ? $row['batch_state'] : '',
                        \number_format(\is_numeric($row['count'] ?? null) ? (int) $row['count'] : 0),
                        $this->formatBytes(\is_numeric($row['total_size'] ?? null) ? (int) $row['total_size'] : 0),
                    ],
                    $uploadedWithBatch
                )
            );
        }

        // Sample UPLOADED files
        $samples = $this->connection->fetchAllAssociative(
            'SELECT j.id, j.state, j.batch_id, b.state as batch_state, j.file_path
             FROM google_photos_upload_jobs j
             LEFT JOIN google_photos_upload_batches b ON j.batch_id = b.id
             WHERE j.state = ?
             LIMIT 10',
            [UploadState::UPLOADED->value]
        );

        if ([] !== $samples) {
            $io->section('Sample UPLOADED Files');
            $io->table(
                ['Job ID', 'Batch ID', 'Batch State', 'File Path'],
                \array_map(
                    fn (array $row): array => [
                        \substr((string) $row['id'], 0, 16).'...',
                        $row['batch_id'] ?? 'NULL',
                        $row['batch_state'] ?? 'NULL',
                        \basename((string) $row['file_path']),
                    ],
                    $samples
                )
            );
        }

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = \max($bytes, 0);
        $pow = \floor(($bytes ? \log($bytes) : 0) / \log(1024));
        $powInt = (int) $pow;
        $powInt = \min($powInt, \count($units) - 1);
        $bytes /= (1 << (10 * $powInt));

        return \round($bytes, 2).' '.$units[$powInt];
    }
}
