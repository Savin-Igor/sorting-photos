<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Adapters\Repository;

use Doctrine\DBAL\Connection;
use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\MetadataRepositoryPort;

final class DatabaseMetadataRepository implements MetadataRepositoryPort
{
    private const TABLE_NAME = 'file_metadata';

    public function __construct(
        private readonly Connection $connection
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
    UNIQUE(file_path, file_size, file_hash),
    INDEX idx_hash (file_hash),
    INDEX idx_path (file_path)
)
SQL;

        $this->connection->executeStatement($sql);
    }

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

            // Check if record exists
            $existing = $this->findByPathSizeAndHash(
                $asset->getSourcePath(),
                $asset->getFileSize(),
                $asset->getHash()
            );

            if ($existing !== null) {
                // Update existing record
                $this->connection->update(
                    self::TABLE_NAME,
                    $data,
                    [
                        'file_path' => $asset->getSourcePath()->getPath(),
                        'file_size' => $asset->getFileSize(),
                        'file_hash' => $asset->getHash()->getHash(),
                    ]
                );
            } else {
                // Insert new record
                $this->connection->insert(self::TABLE_NAME, $data);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function findByPathSizeAndHash(FilePath $filePath, int $fileSize, FileHash $hash): ?MediaAsset
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM ' . self::TABLE_NAME . ' WHERE file_path = ? AND file_size = ? AND file_hash = ? LIMIT 1',
            [$filePath->getPath(), $fileSize, $hash->getHash()]
        );

        if ($result === false) {
            return null;
        }

        return $this->hydrateAsset($result);
    }

    public function findByHash(FileHash $hash): ?MediaAsset
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM ' . self::TABLE_NAME . ' WHERE file_hash = ? LIMIT 1',
            [$hash->getHash()]
        );

        if ($result === false) {
            return null;
        }

        return $this->hydrateAsset($result);
    }

    public function isProcessed(FilePath $filePath, int $fileSize, FileHash $hash): bool
    {
        return $this->findByPathSizeAndHash($filePath, $fileSize, $hash) !== null;
    }

    public function delete(FilePath $filePath): bool
    {
        try {
            $affected = $this->connection->delete(
                self::TABLE_NAME,
                ['file_path' => $filePath->getPath()]
            );

            return $affected > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function hydrateAsset(array $row): MediaAsset
    {
        $sourcePath = new FilePath($row['file_path']);
        $fileType = \SortingPhotosByDate\Domain\ValueObjects\FileType::from($row['file_type']);
        $hash = new FileHash($row['file_hash']);

        $metadata = new \SortingPhotosByDate\Domain\ValueObjects\MediaMeta(
            $row['file_name'],
            $row['mime_type'],
            (int) $row['file_size'],
            $row['width'] !== null ? (int) $row['width'] : null,
            $row['height'] !== null ? (int) $row['height'] : null,
            $row['duration'] !== null ? (int) $row['duration'] : null,
            null,
            null,
            null,
            $row['metadata_json'] !== null ? json_decode($row['metadata_json'], true, 512, JSON_THROW_ON_ERROR) : []
        );

        $date = \SortingPhotosByDate\Domain\ValueObjects\MediaDate::fromString($row['date_taken']);

        return new \SortingPhotosByDate\Domain\MediaAsset($sourcePath, $fileType, $metadata, $date, $hash);
    }
}

