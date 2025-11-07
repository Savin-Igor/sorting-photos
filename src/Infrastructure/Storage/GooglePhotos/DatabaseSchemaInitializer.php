<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use Doctrine\DBAL\Connection;

final readonly class DatabaseSchemaInitializer
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function initializeSchema(): void
    {
        $this->createUploadJobsTable();
        $this->createUploadBatchesTable();
        $this->createQuotaStatusTable();
        $this->createDistributedLocksTable();
        $this->createTokensTable();
    }

    private function createUploadJobsTable(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS google_photos_upload_jobs (
    id VARCHAR(64) PRIMARY KEY,
    file_path VARCHAR(2048) NOT NULL,
    file_size INTEGER NOT NULL,
    file_hash VARCHAR(64) NOT NULL,
    mime_type VARCHAR(128) NOT NULL,
    is_video BOOLEAN NOT NULL,
    state VARCHAR(32) NOT NULL,
    resumable_session_uri TEXT NULL,
    uploaded_bytes INTEGER DEFAULT 0,
    upload_token TEXT NULL,
    batch_id VARCHAR(64) NULL,
    creation_time DATETIME NOT NULL,
    retry_count INTEGER DEFAULT 0,
    last_error TEXT NULL,
    last_known_uploaded_bytes INTEGER NULL,
    session_expiration_count INTEGER DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
)
SQL;

        $this->connection->executeStatement($sql);

        // Создать индексы
        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_upload_jobs_state ON google_photos_upload_jobs(state)',
            'CREATE INDEX IF NOT EXISTS idx_upload_jobs_hash ON google_photos_upload_jobs(file_hash)',
            'CREATE INDEX IF NOT EXISTS idx_upload_jobs_batch_id ON google_photos_upload_jobs(batch_id)',
            'CREATE INDEX IF NOT EXISTS idx_upload_jobs_ready_for_batch ON google_photos_upload_jobs(state, upload_token, batch_id)',
        ];

        foreach ($indexes as $indexSql) {
            try {
                $this->connection->executeStatement($indexSql);
            } catch (\Exception) {
                // Индекс может уже существовать
            }
        }
    }

    private function createUploadBatchesTable(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS google_photos_upload_batches (
    id VARCHAR(64) PRIMARY KEY,
    state VARCHAR(32) NOT NULL,
    current_index INTEGER DEFAULT 0,
    total_items INTEGER NOT NULL,
    total_size INTEGER NOT NULL,
    items_json TEXT NOT NULL,
    error_message TEXT NULL,
    quota_reset_time DATETIME NULL,
    created_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    updated_at DATETIME NOT NULL
)
SQL;

        $this->connection->executeStatement($sql);

        // Создать индексы
        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_upload_batches_state ON google_photos_upload_batches(state)',
            'CREATE INDEX IF NOT EXISTS idx_upload_batches_quota_reset ON google_photos_upload_batches(quota_reset_time)',
        ];

        foreach ($indexes as $indexSql) {
            try {
                $this->connection->executeStatement($indexSql);
            } catch (\Exception) {
                // Индекс может уже существовать
            }
        }
    }

    private function createQuotaStatusTable(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS google_photos_quota_status (
    date DATE PRIMARY KEY,
    requests_used INTEGER DEFAULT 0,
    requests_limit INTEGER NOT NULL,
    bytes_used INTEGER DEFAULT 0,
    bytes_limit INTEGER NOT NULL,
    updated_at DATETIME NOT NULL
)
SQL;

        $this->connection->executeStatement($sql);
    }

    private function createDistributedLocksTable(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS distributed_locks (
    lock_name VARCHAR(64) PRIMARY KEY,
    process_id VARCHAR(64) NOT NULL,
    acquired_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL
)
SQL;

        $this->connection->executeStatement($sql);

        // Создать индекс
        try {
            $this->connection->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_distributed_locks_expires ON distributed_locks(expires_at)'
            );
        } catch (\Exception) {
            // Индекс может уже существовать
        }
    }

    private function createTokensTable(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS google_photos_tokens (
    refresh_token TEXT NOT NULL,
    access_token TEXT NULL,
    expires_at DATETIME NULL,
    updated_at DATETIME NOT NULL
)
SQL;

        $this->connection->executeStatement($sql);
    }
}
