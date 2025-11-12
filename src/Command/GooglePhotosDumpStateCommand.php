<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google-photos:dump-state',
    description: 'Dump complete application state (excluding authentication data)'
)]
final class GooglePhotosDumpStateCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (json, table)', 'table')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit number of jobs to show', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = $input->getOption('format');
        $limit = (int) $input->getOption('limit');

        $state = $this->collectState($limit);

        if ('json' === $format) {
            $output->writeln(\json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        // Table format
        $this->displayState($io, $state);

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectState(int $limit): array
    {
        return [
            'timestamp' => new \DateTimeImmutable()->format('c'),
            'statistics' => $this->getStatistics(),
            'upload_jobs' => $this->getUploadJobs($limit),
            'upload_batches' => $this->getUploadBatches(),
            'quota_status' => $this->getQuotaStatus(),
            'distributed_locks' => $this->getDistributedLocks(),
            'file_metadata' => $this->getFileMetadata($limit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getStatistics(): array
    {
        $jobStats = $this->connection->fetchAllAssociative(
            'SELECT state, COUNT(*) as count, 
                    SUM(file_size) as total_size,
                    AVG(file_size) as avg_size
             FROM google_photos_upload_jobs 
             GROUP BY state'
        );

        $batchStats = $this->connection->fetchAllAssociative(
            'SELECT state, COUNT(*) as count 
             FROM google_photos_upload_batches 
             GROUP BY state'
        );

        $totalJobs = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM google_photos_upload_jobs'
        );

        $totalSize = $this->connection->fetchOne(
            'SELECT SUM(file_size) FROM google_photos_upload_jobs'
        );

        return [
            'jobs_by_state' => \array_map(
                fn (array $row): array => [
                    'state' => $row['state'],
                    'count' => (int) $row['count'],
                    'total_size' => (int) ($row['total_size'] ?? 0),
                    'avg_size' => (float) ($row['avg_size'] ?? 0),
                ],
                $jobStats
            ),
            'batches_by_state' => \array_map(
                fn (array $row): array => [
                    'state' => $row['state'],
                    'count' => (int) $row['count'],
                ],
                $batchStats
            ),
            'total_jobs' => (int) $totalJobs,
            'total_size_bytes' => (int) ($totalSize ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getUploadJobs(int $limit): array
    {
        $jobs = $this->connection->fetchAllAssociative(
            'SELECT 
                id,
                file_path,
                file_size,
                file_hash,
                mime_type,
                is_video,
                state,
                uploaded_bytes,
                batch_id,
                creation_time,
                retry_count,
                last_error,
                last_known_uploaded_bytes,
                session_expiration_count,
                created_at,
                updated_at
             FROM google_photos_upload_jobs
             ORDER BY updated_at DESC
             LIMIT ?',
            [$limit]
        );

        return \array_map(
            fn (array $row): array => [
                'id' => $row['id'],
                'file_path' => $row['file_path'],
                'file_name' => \basename((string) $row['file_path']),
                'file_size' => (int) $row['file_size'],
                'file_hash' => $row['file_hash'],
                'mime_type' => $row['mime_type'],
                'is_video' => (bool) $row['is_video'],
                'state' => $row['state'],
                'uploaded_bytes' => (int) ($row['uploaded_bytes'] ?? 0),
                'upload_progress_percent' => $row['file_size'] > 0
                    ? \round((($row['uploaded_bytes'] ?? 0) / $row['file_size']) * 100, 2)
                    : 0,
                'batch_id' => $row['batch_id'],
                'creation_time' => $row['creation_time'],
                'retry_count' => (int) $row['retry_count'],
                'last_error' => $row['last_error'],
                'last_known_uploaded_bytes' => null !== $row['last_known_uploaded_bytes'] ? (int) $row['last_known_uploaded_bytes'] : null,
                'session_expiration_count' => (int) $row['session_expiration_count'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
            $jobs
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getUploadBatches(): array
    {
        $batches = $this->connection->fetchAllAssociative(
            'SELECT 
                id,
                state,
                current_index,
                total_items,
                total_size,
                error_message,
                quota_reset_time,
                created_at,
                started_at,
                completed_at,
                updated_at
             FROM google_photos_upload_batches
             ORDER BY created_at DESC'
        );

        return \array_map(
            fn (array $row): array => [
                'id' => $row['id'],
                'state' => $row['state'],
                'current_index' => (int) $row['current_index'],
                'total_items' => (int) $row['total_items'],
                'total_size' => (int) $row['total_size'],
                'error_message' => $row['error_message'],
                'quota_reset_time' => $row['quota_reset_time'],
                'created_at' => $row['created_at'],
                'started_at' => $row['started_at'],
                'completed_at' => $row['completed_at'],
                'updated_at' => $row['updated_at'],
            ],
            $batches
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getQuotaStatus(): array
    {
        $quota = $this->connection->fetchAssociative(
            'SELECT 
                requests_used,
                requests_limit,
                bytes_used,
                bytes_limit,
                date,
                updated_at
             FROM google_photos_quota_status
             ORDER BY date DESC
             LIMIT 1'
        );

        if (false === $quota) {
            return [
                'requests_used' => null,
                'requests_limit' => null,
                'bytes_used' => null,
                'bytes_limit' => null,
                'date' => null,
                'updated_at' => null,
            ];
        }

        $requestsLimit = (int) ($quota['requests_limit'] ?? 0);
        $requestsUsed = (int) ($quota['requests_used'] ?? 0);

        return [
            'requests_used' => $requestsUsed,
            'requests_limit' => $requestsLimit,
            'requests_percentage' => $requestsLimit > 0
                ? \round(($requestsUsed / $requestsLimit) * 100, 2)
                : 0,
            'bytes_used' => (int) ($quota['bytes_used'] ?? 0),
            'bytes_limit' => (int) ($quota['bytes_limit'] ?? 0),
            'date' => $quota['date'],
            'updated_at' => $quota['updated_at'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDistributedLocks(): array
    {
        $locks = $this->connection->fetchAllAssociative(
            'SELECT 
                lock_name,
                process_id,
                acquired_at,
                expires_at
             FROM distributed_locks
             ORDER BY acquired_at DESC'
        );

        return \array_map(
            fn (array $row): array => [
                'lock_name' => $row['lock_name'],
                'process_id' => $row['process_id'],
                'acquired_at' => $row['acquired_at'],
                'expires_at' => $row['expires_at'],
            ],
            $locks
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getFileMetadata(int $limit): array
    {
        $metadata = $this->connection->fetchAllAssociative(
            'SELECT 
                file_path,
                file_size,
                file_hash,
                mime_type,
                is_video,
                creation_time,
                state,
                created_at,
                updated_at
             FROM google_photos_upload_jobs
             ORDER BY created_at DESC
             LIMIT ?',
            [$limit]
        );

        return \array_map(
            fn (array $row): array => [
                'file_path' => $row['file_path'],
                'file_name' => \basename((string) $row['file_path']),
                'file_size' => (int) $row['file_size'],
                'file_hash' => $row['file_hash'],
                'mime_type' => $row['mime_type'],
                'is_video' => (bool) $row['is_video'],
                'creation_time' => $row['creation_time'],
                'state' => $row['state'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
            $metadata
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private function displayState(SymfonyStyle $io, array $state): void
    {
        $io->title('Application State Dump');
        $io->text(\sprintf('Generated at: %s', $state['timestamp']));

        // Statistics
        $io->section('Statistics');
        $stats = $state['statistics'];
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total Jobs', $stats['total_jobs']],
                ['Total Size', $this->formatBytes($stats['total_size_bytes'])],
            ]
        );

        $io->text('Jobs by State:');
        $io->table(
            ['State', 'Count', 'Total Size', 'Avg Size'],
            \array_map(
                fn (array $row): array => [
                    $row['state'],
                    $row['count'],
                    $this->formatBytes($row['total_size']),
                    $this->formatBytes((int) $row['avg_size']),
                ],
                $stats['jobs_by_state']
            )
        );

        $io->text('Batches by State:');
        $io->table(
            ['State', 'Count'],
            \array_map(
                fn (array $row): array => [$row['state'], $row['count']],
                $stats['batches_by_state']
            )
        );

        // Quota Status
        $io->section('Quota Status');
        $quota = $state['quota_status'];
        if (null !== $quota['requests_limit']) {
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Requests Used', $quota['requests_used'].' / '.$quota['requests_limit'].' ('.$quota['requests_percentage'].'%)'],
                    ['Bytes Used', $this->formatBytes($quota['bytes_used']).' / '.$this->formatBytes($quota['bytes_limit'])],
                    ['Date', $quota['date'] ?? 'N/A'],
                    ['Updated At', $quota['updated_at'] ?? 'N/A'],
                ]
            );
        } else {
            $io->note('No quota data available');
        }

        // Distributed Locks
        $io->section('Distributed Locks');
        $locks = $state['distributed_locks'];
        if ([] !== $locks) {
            $io->table(
                ['Lock Name', 'Process ID', 'Acquired At', 'Expires At'],
                \array_map(
                    fn (array $lock): array => [
                        $lock['lock_name'],
                        $lock['process_id'],
                        $lock['acquired_at'],
                        $lock['expires_at'],
                    ],
                    $locks
                )
            );
        } else {
            $io->note('No active locks');
        }

        // Upload Batches
        $io->section('Upload Batches');
        $batches = $state['upload_batches'];
        if ([] !== $batches) {
            $io->table(
                ['ID', 'State', 'Items', 'Size', 'Error', 'Created'],
                \array_map(
                    fn (array $batch): array => [
                        \substr((string) $batch['id'], 0, 8).'...',
                        $batch['state'],
                        $batch['current_index'].' / '.$batch['total_items'],
                        $this->formatBytes($batch['total_size']),
                        $batch['error_message'] ?? '-',
                        $batch['created_at'],
                    ],
                    \array_slice($batches, 0, 20)
                )
            );
            if (\count($batches) > 20) {
                $io->note(\sprintf('Showing first 20 of %d batches', \count($batches)));
            }
        } else {
            $io->note('No batches');
        }

        // Recent Jobs
        $io->section('Recent Upload Jobs');
        $jobs = $state['upload_jobs'];
        if ([] !== $jobs) {
            $io->table(
                ['File', 'Size', 'State', 'Progress', 'Updated'],
                \array_map(
                    fn (array $job): array => [
                        \substr((string) $job['file_name'], 0, 40),
                        $this->formatBytes($job['file_size']),
                        $job['state'],
                        'uploading' === $job['state'] ? $job['upload_progress_percent'].'%' : '-',
                        $job['updated_at'],
                    ],
                    \array_slice($jobs, 0, 30)
                )
            );
            if (\count($jobs) > 30) {
                $io->note(\sprintf('Showing first 30 of %d jobs', \count($jobs)));
            }
        } else {
            $io->note('No jobs');
        }

        // File Metadata Summary
        $io->section('File Metadata Summary');
        $metadata = $state['file_metadata'];
        $mimeTypes = [];
        $videoCount = 0;
        $imageCount = 0;

        foreach ($metadata as $file) {
            $mimeType = $file['mime_type'];
            if (!isset($mimeTypes[$mimeType])) {
                $mimeTypes[$mimeType] = 0;
            }
            ++$mimeTypes[$mimeType];

            if ($file['is_video']) {
                ++$videoCount;
            } else {
                ++$imageCount;
            }
        }

        $io->table(
            ['Type', 'Count'],
            [
                ['Images', $imageCount],
                ['Videos', $videoCount],
            ]
        );

        $io->text('MIME Types:');
        $io->table(
            ['MIME Type', 'Count'],
            \array_map(
                fn (string $mime, int $count): array => [$mime, $count],
                \array_keys($mimeTypes),
                \array_values($mimeTypes)
            )
        );
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = \max($bytes, 0);
        $pow = \floor(($bytes ? \log($bytes) : 0) / \log(1024));
        $pow = \min($pow, \count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return \round($bytes, 2).' '.$units[$pow];
    }
}
