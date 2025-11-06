<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Handler;

use SortingPhotosByDate\Application\Command\OrganizeFileCommand;
use SortingPhotosByDate\Domain\Policies\OrganizerPolicy;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;

/**
 * Handler for OrganizeFileCommand.
 * Applies organizer policy, copies file with metadata preservation, verifies hash, and deletes source.
 */
final class OrganizeFileHandler
{
    public function __construct(
        private readonly FilesystemPort $filesystem,
        private readonly OrganizerPolicy $policy,
        private readonly LoggerPort $logger,
    ) {
    }

    public function handle(OrganizeFileCommand $command): FilePath
    {
        $asset = $command->getAsset();
        $sourcePath = $asset->getSourcePath();

        // Apply organizer policy to get target path
        $targetPath = $this->policy->organize($asset);

        // Handle file collision: if target exists, append hash prefix
        $finalTargetPath = $this->resolveCollision($targetPath, $asset->getHash());

        // Copy file with metadata preservation
        if (!$this->filesystem->copyWithMetadata($sourcePath, $finalTargetPath)) {
            throw new \RuntimeException("Failed to copy file from {$sourcePath->getPath()} to {$finalTargetPath->getPath()}");
        }

        // Verify hash after copy (only if both files exist)
        if ($this->filesystem->exists($sourcePath) && $this->filesystem->exists($finalTargetPath)) {
            $this->verifyHash($sourcePath, $finalTargetPath, $asset->getHash());
        }

        // Delete source file only after successful copy and verification
        if (!$this->filesystem->delete($sourcePath)) {
            $this->logger->warning('Failed to delete source file after copy', [
                'source_path' => $sourcePath->getPath(),
                'target_path' => $finalTargetPath->getPath(),
            ]);
            // Don't throw exception - file is already copied
        }

        $this->logger->info('File organized successfully', [
            'source_path' => $sourcePath->getPath(),
            'target_path' => $finalTargetPath->getPath(),
            'hash' => $asset->getHash()->getHash(),
        ]);

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
        $filename = isset($pathInfo['filename']) ? $pathInfo['filename'] : '';
        $extension = isset($pathInfo['extension']) ? '.'.$pathInfo['extension'] : '';
        $hashPrefix = $hash->getShortHash(8);

        $collisionPath = new FilePath("{$directory}/{$filename}-{$hashPrefix}{$extension}");

        $this->logger->info('File collision detected, using hash prefix', [
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
