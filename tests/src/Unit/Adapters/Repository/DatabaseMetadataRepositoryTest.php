<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Adapters\Repository;

use Carbon\Carbon;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Adapters\Repository\DatabaseMetadataRepository;
use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Domain\ValueObjects\FileType;
use SortingPhotosByDate\Domain\ValueObjects\MediaDate;
use SortingPhotosByDate\Domain\ValueObjects\MediaMeta;

final class DatabaseMetadataRepositoryTest extends TestCase
{
    private Connection $connection;
    private DatabaseMetadataRepository $repository;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('SQLite extension is not available');
        }

        // Use in-memory SQLite for testing
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->repository = new DatabaseMetadataRepository($this->connection);
        $this->repository->initializeSchema();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testSaveAndFindByPathSizeAndHash(): void
    {
        $asset = $this->createTestAsset('/test/image.jpg', 'a1b2c3d4e5f6');

        $this->assertTrue($this->repository->save($asset));

        $found = $this->repository->findByPathSizeAndHash(
            new FilePath('/test/image.jpg'),
            1024,
            new FileHash('a1b2c3d4e5f6')
        );

        $this->assertNotNull($found);
        $this->assertEquals('/test/image.jpg', $found->getSourcePath()->getPath());
        $this->assertEquals(1024, $found->getFileSize());
        $this->assertEquals('a1b2c3d4e5f6', $found->getHash()->getHash());
    }

    public function testFindByHash(): void
    {
        $asset = $this->createTestAsset('/test/image.jpg', 'a1b2c3d4e5f6');
        $this->repository->save($asset);

        $found = $this->repository->findByHash(new FileHash('a1b2c3d4e5f6'));

        $this->assertNotNull($found);
        $this->assertEquals('a1b2c3d4e5f6', $found->getHash()->getHash());
    }

    public function testFindByHashReturnsNullWhenNotFound(): void
    {
        $found = $this->repository->findByHash(new FileHash('nonexistent'));

        $this->assertNull($found);
    }

    public function testIsProcessedReturnsTrueForProcessedFile(): void
    {
        $asset = $this->createTestAsset('/test/image.jpg', 'a1b2c3d4e5f6');
        $this->repository->save($asset);

        $isProcessed = $this->repository->isProcessed(
            new FilePath('/test/image.jpg'),
            1024,
            new FileHash('a1b2c3d4e5f6')
        );

        $this->assertTrue($isProcessed);
    }

    public function testIsProcessedReturnsFalseForUnprocessedFile(): void
    {
        $isProcessed = $this->repository->isProcessed(
            new FilePath('/test/image.jpg'),
            1024,
            new FileHash('a1b2c3d4e5f6')
        );

        $this->assertFalse($isProcessed);
    }

    public function testIsProcessedReturnsFalseForDifferentHash(): void
    {
        $asset = $this->createTestAsset('/test/image.jpg', 'a1b2c3d4e5f6');
        $this->repository->save($asset);

        $isProcessed = $this->repository->isProcessed(
            new FilePath('/test/image.jpg'),
            1024,
            new FileHash('different-hash')
        );

        $this->assertFalse($isProcessed);
    }

    public function testIsProcessedReturnsFalseForDifferentSize(): void
    {
        $asset = $this->createTestAsset('/test/image.jpg', 'a1b2c3d4e5f6');
        $this->repository->save($asset);

        $isProcessed = $this->repository->isProcessed(
            new FilePath('/test/image.jpg'),
            2048, // Different size
            new FileHash('a1b2c3d4e5f6')
        );

        $this->assertFalse($isProcessed);
    }

    public function testDelete(): void
    {
        $asset = $this->createTestAsset('/test/image.jpg', 'a1b2c3d4e5f6');
        $this->repository->save($asset);

        $this->assertTrue($this->repository->delete(new FilePath('/test/image.jpg')));

        $found = $this->repository->findByPathSizeAndHash(
            new FilePath('/test/image.jpg'),
            1024,
            new FileHash('a1b2c3d4e5f6')
        );

        $this->assertNull($found);
    }

    public function testDeleteNonExistentFileReturnsFalse(): void
    {
        $result = $this->repository->delete(new FilePath('/nonexistent.jpg'));

        $this->assertFalse($result);
    }

    public function testSaveUpdatesExistingFile(): void
    {
        $asset1 = $this->createTestAsset('/test/image.jpg', 'a1b2c3d4e5f6');
        $this->repository->save($asset1);

        $asset2 = $this->createTestAsset('/test/image.jpg', 'f6e5d4c3b2a1');
        $this->repository->save($asset2);

        $found = $this->repository->findByPathSizeAndHash(
            new FilePath('/test/image.jpg'),
            1024,
            new FileHash('f6e5d4c3b2a1')
        );

        $this->assertNotNull($found);
        $this->assertEquals('f6e5d4c3b2a1', $found->getHash()->getHash());
    }

    public function testSaveMultipleFilesWithDifferentPaths(): void
    {
        $asset1 = $this->createTestAsset('/test/image1.jpg', 'a1b2c3d4e5f6');
        $asset2 = $this->createTestAsset('/test/image2.jpg', 'f6e5d4c3b2a1');

        $this->assertTrue($this->repository->save($asset1));
        $this->assertTrue($this->repository->save($asset2));

        $found1 = $this->repository->findByPathSizeAndHash(
            new FilePath('/test/image1.jpg'),
            1024,
            new FileHash('a1b2c3d4e5f6')
        );

        $found2 = $this->repository->findByPathSizeAndHash(
            new FilePath('/test/image2.jpg'),
            1024,
            new FileHash('f6e5d4c3b2a1')
        );

        $this->assertNotNull($found1);
        $this->assertNotNull($found2);
    }

    public function testFindByHashReturnsFirstMatchForDuplicateHashes(): void
    {
        $asset1 = $this->createTestAsset('/test/image1.jpg', 'a1b2c3d4e5f6');
        $asset2 = $this->createTestAsset('/test/image2.jpg', 'a1b2c3d4e5f6');

        $this->repository->save($asset1);
        $this->repository->save($asset2);

        $found = $this->repository->findByHash(new FileHash('a1b2c3d4e5f6'));

        $this->assertNotNull($found);
        // Should return one of the files with this hash
        $this->assertEquals('a1b2c3d4e5f6', $found->getHash()->getHash());
    }

    private function createTestAsset(string $path, string $hash): MediaAsset
    {
        $sourcePath = new FilePath($path);
        $fileType = FileType::IMAGE;
        $metadata = new MediaMeta('image.jpg', 'image/jpeg', 1024, 1920, 1080);
        $date = new MediaDate(Carbon::parse('2021-09-28'));
        $fileHash = new FileHash($hash);

        return new MediaAsset($sourcePath, $fileType, $metadata, $date, $fileHash);
    }
}
