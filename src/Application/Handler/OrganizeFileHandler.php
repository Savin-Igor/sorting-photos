<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Handler;

use SortingPhotosByDate\Application\Command\OrganizeFileCommand;
use SortingPhotosByDate\Application\Service\FileCompressionServiceInterface;
use SortingPhotosByDate\Domain\Event\FileOrganized;
use SortingPhotosByDate\Domain\Policies\OrganizerPolicy;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Statistics\RedisStatisticsService;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\MetadataRepositoryPort;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handler for OrganizeFileCommand.
 * Applies organizer policy, copies file with metadata preservation, verifies hash, and deletes source.
 * Includes duplicate detection to prevent copying identical files (byte-by-byte identical).
 */
final readonly class OrganizeFileHandler
{
    public function __construct(
        private FilesystemPort $filesystem,
        private OrganizerPolicy $policy,
        private MetadataRepositoryPort $repository,
        private LoggerPort $logger,
        private MessageBusInterface $messageBus,
        private FileCompressionServiceInterface $compressionService,
        private ?RedisStatisticsService $statistics = null,
    ) {
    }

    public function handle(OrganizeFileCommand $command): FilePath
    {
        $asset = $command->getAsset();
        $sourcePath = $asset->getSourcePath();

        // Check for duplicate: if file with same hash already exists, skip copying
        $existingAsset = $this->repository->findByHash($asset->getHash());
        if ($existingAsset instanceof \SortingPhotosByDate\Domain\MediaAsset) {
            // Check if existing file is different from current source (duplicate)
            $existingPath = $existingAsset->getSourcePath();
            if ($existingPath->getPath() !== $sourcePath->getPath()) {
                $this->logger->debug('Duplicate file detected, skipping organization', [
                    'source_path' => $sourcePath->getPath(),
                    'source_size' => $asset->getFileSize(),
                    'hash' => $asset->getHash()->getHash(),
                    'existing_file_path' => $existingPath->getPath(),
                    'existing_file_size' => $existingAsset->getFileSize(),
                ]);

                // Return existing path as if we organized it (but we didn't copy)
                return $existingPath;
            }
        }

        // Compress file if needed (before organizing)
        $compressedPath = $this->compressionService->compressIfNeeded($sourcePath, $asset);
        $isTemporaryCompressed = $this->compressionService->isTemporaryFile($compressedPath);

        // Apply organizer policy to get target path
        $targetPath = $this->policy->organize($asset);

        // Handle file collision: if target exists, append hash prefix
        $finalTargetPath = $this->resolveCollision($targetPath, $asset->getHash());

        // Copy compressed file (if compressed) or original file with metadata preservation
        if (!$this->filesystem->copyWithMetadata($compressedPath, $finalTargetPath)) {
            throw new \RuntimeException("Failed to copy file from {$compressedPath->getPath()} to {$finalTargetPath->getPath()}");
        }

        // Clean up temporary compressed file if it was created
        if ($isTemporaryCompressed && $this->filesystem->exists($compressedPath) && !$this->filesystem->delete($compressedPath)) {
            $this->logger->warning('Failed to delete temporary compressed file', [
                'compressed_path' => $compressedPath->getPath(),
            ]);
        }

        // Verify hash after copy (only if both files exist)
        if ($this->filesystem->exists($sourcePath) && $this->filesystem->exists($finalTargetPath)) {
            $this->verifyHash($sourcePath, $finalTargetPath, $asset->getHash());
        }

        // Delete source file only after successful copy and verification
        if (!$this->filesystem->delete($sourcePath)) {
            $this->logger->debug('Failed to delete source file after copy', [
                'source_path' => $sourcePath->getPath(),
                'target_path' => $finalTargetPath->getPath(),
            ]);
            // Don't throw exception - file is already copied
        }

        $this->logger->debug('File organized successfully', [
            'source_path' => $sourcePath->getPath(),
            'target_path' => $finalTargetPath->getPath(),
            'hash' => $asset->getHash()->getHash(),
        ]);

        // Increment processed counter
        if ($this->statistics instanceof RedisStatisticsService) {
            $this->statistics->incrementProcessed();
        }

        // Dispatch FileOrganized event
        $event = new FileOrganized($asset, $sourcePath, $finalTargetPath);
        $this->messageBus->dispatch($event);

        return $finalTargetPath;
    }

    /**
     * Resolve file collision by appending hash prefix if target exists.
     */
    private function resolveCollision(FilePath $targetPath, \SortingPhotosByDate\Domain\ValueObjects\FileHash $hash): FilePath
    {
        if (!$this->filesystem->exists($targetPath)) {
            return $targetPath;
        }

        // File exists - create collision path with hash prefix
        $pathInfo = pathinfo($targetPath->getPath());
        $directory = $pathInfo['dirname'] ?? '.';
        // Note: 'filename' key always exists in pathinfo result, so no need for ??
        $filename = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? '.'.$pathInfo['extension'] : '';
        $hashPrefix = $hash->getShortHash(8);

        $collisionPath = new FilePath("{$directory}/{$filename}-{$hashPrefix}{$extension}");

        $this->logger->debug('File collision detected, using hash prefix', [
            'original_path' => $targetPath->getPath(),
            'collision_path' => $collisionPath->getPath(),
        ]);

        return $collisionPath;
    }

    /**
     * Verify hash of copied file matches original.
     */
    private function verifyHash(
        FilePath $sourcePath,
        FilePath $targetPath,
        \SortingPhotosByDate\Domain\ValueObjects\FileHash $expectedHash,
    ): void {
        try {
            $sourceHashString = $this->filesystem->calculateHash($sourcePath);
            $targetHashString = $this->filesystem->calculateHash($targetPath);

            $sourceHash = new \SortingPhotosByDate\Domain\ValueObjects\FileHash($sourceHashString);
            $targetHash = new \SortingPhotosByDate\Domain\ValueObjects\FileHash($targetHashString);

            if (!$sourceHash->equals($targetHash)) {
                throw new \RuntimeException("Hash verification failed: source hash {$sourceHash->getHash()} does not match target hash {$targetHash->getHash()}");
            }

            if (!$expectedHash->equals($targetHash)) {
                throw new \RuntimeException("Hash verification failed: expected hash {$expectedHash->getHash()} does not match target hash {$targetHash->getHash()}");
            }
        } catch (\Exception $e) {
            $this->logger->error('Hash verification failed', [
                'source_path' => $sourcePath->getPath(),
                'target_path' => $targetPath->getPath(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
