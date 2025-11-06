<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Repository;

use Doctrine\DBAL\Connection;
use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\MetadataRepositoryPort;

final readonly class DatabaseMetadataRepository implements MetadataRepositoryPort
{
    private const string TABLE_NAME = 'file_metadata';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Initialize database schema.
     */
    public function initializeSchema(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS file_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_path VARCHAR(2048) NOT NULL,
    file_name VARCHAR(512) NOT NULL,
    file_size INTEGER NOT NULL,
    file_hash VARCHAR(64) NOT NULL,
    mime_type VARCHAR(128) NOT NULL,
    file_type VARCHAR(32) NOT NULL,
    category VARCHAR(32) NOT NULL,
    date_taken DATETIME NOT NULL,
    width INTEGER,
    height INTEGER,
    duration INTEGER,
    metadata_json TEXT,
    created_at DATETIME NOT NULL,
    UNIQUE(file_path, file_size, file_hash)
)
SQL;

        $this->connection->executeStatement($sql);

        // Create indexes separately (SQLite doesn't support INDEX in CREATE TABLE)
        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_hash ON file_metadata(file_hash)',
            'CREATE INDEX IF NOT EXISTS idx_path ON file_metadata(file_path)',
        ];

        foreach ($indexes as $indexSql) {
            try {
                $this->connection->executeStatement($indexSql);
            } catch (\Exception) {
                // Index might already exist, ignore
            }
        }
    }

    #[\Override]
    public function save(MediaAsset $asset): bool
    {
        try {
            $data = [
                'file_path' => $asset->getSourcePath()->getPath(),
                'file_name' => $asset->getFileName(),
                'file_size' => $asset->getFileSize(),
                'file_hash' => $asset->getHash()->getHash(),
                'mime_type' => $asset->getMimeType(),
                'file_type' => $asset->getFileType()->value,
                'category' => $asset->getCategory()->value,
                'date_taken' => $asset->getDate()->getDateTime()->format('Y-m-d H:i:s'),
                'width' => $asset->getMetadata()->getWidth(),
                'height' => $asset->getMetadata()->getHeight(),
                'duration' => $asset->getMetadata()->getDuration(),
                'metadata_json' => json_encode($asset->getMetadata()->getAdditionalMetadata(), JSON_THROW_ON_ERROR),
                'created_at' => date('Y-m-d H:i:s'),
            ];

            // Use INSERT OR IGNORE to prevent race conditions in async mode
            // This ensures atomic check-and-insert operation
            // If record already exists (UNIQUE constraint), it will be ignored
            $sql = 'INSERT OR IGNORE INTO '.self::TABLE_NAME.' (
                file_path, file_name, file_size, file_hash, mime_type, file_type, category,
                date_taken, width, height, duration, metadata_json, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

            $params = [
                $data['file_path'],
                $data['file_name'],
                $data['file_size'],
                $data['file_hash'],
                $data['mime_type'],
                $data['file_type'],
                $data['category'],
                $data['date_taken'],
                $data['width'],
                $data['height'],
                $data['duration'],
                $data['metadata_json'],
                $data['created_at'],
            ];

            $affected = $this->connection->executeStatement($sql, $params);

            // If no rows were affected, record already exists - update it
            if (0 === $affected) {
                $this->connection->update(
                    self::TABLE_NAME,
                    $data,
                    [
                        'file_path' => $asset->getSourcePath()->getPath(),
                        'file_size' => $asset->getFileSize(),
                        'file_hash' => $asset->getHash()->getHash(),
                    ]
                );
            }

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    #[\Override]
    public function findByPathSizeAndHash(FilePath $filePath, int $fileSize, FileHash $hash): ?MediaAsset
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE_NAME.' WHERE file_path = ? AND file_size = ? AND file_hash = ? LIMIT 1',
            [$filePath->getPath(), $fileSize, $hash->getHash()]
        );

        if (false === $result) {
            return null;
        }

        return $this->hydrateAsset($result);
    }

    #[\Override]
    public function findByHash(FileHash $hash): ?MediaAsset
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE_NAME.' WHERE file_hash = ? LIMIT 1',
            [$hash->getHash()]
        );

        if (false === $result) {
            return null;
        }

        return $this->hydrateAsset($result);
    }

    #[\Override]
    public function isProcessed(FilePath $filePath, int $fileSize, FileHash $hash): bool
    {
        return $this->findByPathSizeAndHash($filePath, $fileSize, $hash) instanceof MediaAsset;
    }

    #[\Override]
    public function delete(FilePath $filePath): bool
    {
        try {
            $affected = $this->connection->delete(
                self::TABLE_NAME,
                ['file_path' => $filePath->getPath()]
            );

            return $affected > 0;
        } catch (\Exception) {
            return false;
        }
    }

    #[\Override]
    public function clearAll(): bool
    {
        try {
            $this->connection->executeStatement('DELETE FROM '.self::TABLE_NAME);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    #[\Override]
    public function getDuplicateStats(): array
    {
        try {
            // Count total files
            $totalFilesResult = $this->connection->fetchOne('SELECT COUNT(*) FROM '.self::TABLE_NAME);
            $totalFiles = is_numeric($totalFilesResult) ? (int) $totalFilesResult : 0;

            // Count unique hashes
            $uniqueFilesResult = $this->connection->fetchOne('SELECT COUNT(DISTINCT file_hash) FROM '.self::TABLE_NAME);
            $uniqueFiles = is_numeric($uniqueFilesResult) ? (int) $uniqueFilesResult : 0;

            // Count duplicates (files with hash that appears more than once)
            $duplicateFiles = $totalFiles - $uniqueFiles;

            // Calculate total size of duplicate files
            // Sum all file sizes where hash appears more than once, excluding the first occurrence
            $duplicateSize = 0;
            if ($duplicateFiles > 0) {
                // Get all hashes that appear more than once
                $duplicateHashes = $this->connection->fetchFirstColumn(
                    'SELECT file_hash FROM '.self::TABLE_NAME.'
                    GROUP BY file_hash
                    HAVING COUNT(*) > 1'
                );

                // For each duplicate hash, sum sizes of all files except the first one
                foreach ($duplicateHashes as $hash) {
                    $duplicateRows = $this->connection->fetchAllAssociative(
                        'SELECT id, file_size FROM '.self::TABLE_NAME.'
                            WHERE file_hash = ?
                            ORDER BY id ASC',
                        [$hash]
                    );

                    // Skip first occurrence, sum the rest
                    if (count($duplicateRows) > 1) {
                        foreach (array_slice($duplicateRows, 1) as $row) {
                            $rowSize = $row['file_size'] ?? 0;
                            $duplicateSize += is_numeric($rowSize) ? (int) $rowSize : 0;
                        }
                    }
                }
            }

            return [
                'total_files' => $totalFiles,
                'unique_files' => $uniqueFiles,
                'duplicate_files' => $duplicateFiles,
                'duplicate_size' => $duplicateSize,
            ];
        } catch (\Exception) {
            return [
                'total_files' => 0,
                'unique_files' => 0,
                'duplicate_files' => 0,
                'duplicate_size' => 0,
            ];
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateAsset(array $row): MediaAsset
    {
        $sourcePath = new FilePath((string) $row['file_path']);
        $fileType = \SortingPhotosByDate\Domain\ValueObjects\FileType::from(\strtolower((string) $row['file_type']));
        $hash = new FileHash((string) $row['file_hash']);

        /** @var array<array-key, mixed> $additionalMetadata */
        $additionalMetadata = null !== $row['metadata_json'] && is_string($row['metadata_json']) ? json_decode($row['metadata_json'], true, 512, JSON_THROW_ON_ERROR) : [];

        $metadata = new \SortingPhotosByDate\Domain\ValueObjects\MediaMeta(
            (string) $row['file_name'],
            (string) $row['mime_type'],
            is_numeric($row['file_size']) ? (int) $row['file_size'] : 0,
            null !== $row['width'] && is_numeric($row['width']) ? (int) $row['width'] : null,
            null !== $row['height'] && is_numeric($row['height']) ? (int) $row['height'] : null,
            null !== $row['duration'] && is_numeric($row['duration']) ? (int) $row['duration'] : null,
            null,
            null,
            null,
            $additionalMetadata
        );

        $date = \SortingPhotosByDate\Domain\ValueObjects\MediaDate::fromString((string) $row['date_taken']);

        return new MediaAsset($sourcePath, $fileType, $metadata, $date, $hash);
    }
}
