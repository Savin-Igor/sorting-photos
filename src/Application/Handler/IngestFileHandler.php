<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Handler;

use SortingPhotosByDate\Application\Command\IngestFileCommand;
use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FileType;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\MetadataExtractorPort;
use SortingPhotosByDate\Ports\MetadataRepositoryPort;

/**
 * Handler for IngestFileCommand.
 * Detects MIME type, extracts metadata, calculates hash, and saves to repository.
 */
final readonly class IngestFileHandler
{
    public function __construct(
        private FilesystemPort $filesystem,
        private MetadataExtractorPort $metadataExtractor,
        private MetadataRepositoryPort $repository,
        private LoggerPort $logger,
    ) {
    }

    public function handle(IngestFileCommand $command): ?MediaAsset
    {
        $filePath = $command->getFilePath();

        // Check if file exists
        if (!$this->filesystem->exists($filePath)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath->getPath()}");
        }

        // Get file size
        $fileSize = $this->filesystem->getSize($filePath);

        // Extract metadata (includes MIME type)
        $mediaMeta = $this->metadataExtractor->extract($filePath->getPath());
        $mimeType = $mediaMeta->getMimeType();

        // Check if extractor supports this MIME type
        if (!$this->metadataExtractor->supports($mimeType)) {
            $this->logger->warning("No suitable extractor for MIME type: {$mimeType}", [
                'file_path' => $filePath->getPath(),
            ]);
        }

        // Extract date
        $mediaDate = $this->metadataExtractor->extractDate($filePath->getPath());

        // Calculate hash using FilesystemPort
        $hashString = $this->filesystem->calculateHash($filePath);
        $fileHash = new FileHash($hashString);

        // Check if already processed (idempotency)
        if ($this->repository->isProcessed($filePath, $fileSize, $fileHash)) {
            $this->logger->info('File already processed, skipping', [
                'file_path' => $filePath->getPath(),
                'hash' => $fileHash->getHash(),
            ]);

            return null;
        }

        // Determine file type from MIME type
        $fileType = FileType::fromMimeType($mimeType);

        // Create MediaAsset
        $asset = new MediaAsset(
            $filePath,
            $fileType,
            $mediaMeta,
            $mediaDate,
            $fileHash
        );

        // Save to repository
        if (!$this->repository->save($asset)) {
            throw new \RuntimeException("Failed to save asset to repository: {$filePath->getPath()}");
        }

        $this->logger->info('File ingested successfully', [
            'file_path' => $filePath->getPath(),
            'file_type' => $fileType->value,
            'hash' => $fileHash->getHash(),
        ]);

        return $asset;
    }
}
