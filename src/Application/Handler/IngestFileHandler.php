<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Handler;

use SortingPhotosByDate\Application\Command\IngestFileCommand;
use SortingPhotosByDate\Domain\Event\FileProcessed;
use SortingPhotosByDate\Domain\Event\FileSkipped;
use SortingPhotosByDate\Domain\MediaAsset;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FileType;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\MetadataExtractorPort;
use SortingPhotosByDate\Ports\MetadataRepositoryPort;
use Symfony\Component\Messenger\MessageBusInterface;

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
        private MessageBusInterface $messageBus,
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

        // Check for duplicate files (same hash, different path)
        $existingAsset = $this->repository->findByHash($fileHash);
        if ($existingAsset instanceof MediaAsset) {
            // File with same hash already exists - this is a duplicate
            // Check if it's the same file (same path) or a different file (duplicate)
            $existingPath = $existingAsset->getSourcePath();
            if ($existingPath->getPath() !== $filePath->getPath()) {
                // Different path, same hash - this is a duplicate
                $this->logger->debug('Duplicate file detected (same hash, different path), skipping ingestion', [
                    'file_path' => $filePath->getPath(),
                    'file_size' => $fileSize,
                    'hash' => $fileHash->getHash(),
                    'existing_file_path' => $existingPath->getPath(),
                    'existing_file_size' => $existingAsset->getFileSize(),
                ]);

                // Dispatch FileSkipped event
                $skippedEvent = new FileSkipped($filePath, 'duplicate', $existingPath->getPath());
                $this->messageBus->dispatch($skippedEvent);

                return null;
            }
            // Same path and hash - already processed, will be caught by isProcessed() check below
        }

        // Check if already processed (idempotency - same path, size, hash)
        // This check prevents reprocessing, but in async mode there's still a race condition
        // The save() method uses INSERT OR IGNORE to handle concurrent access atomically
        if ($this->repository->isProcessed($filePath, $fileSize, $fileHash)) {
            $this->logger->debug('File already processed, skipping', [
                'file_path' => $filePath->getPath(),
                'hash' => $fileHash->getHash(),
            ]);

            // Dispatch FileSkipped event
            $skippedEvent = new FileSkipped($filePath, 'already_processed');
            $this->messageBus->dispatch($skippedEvent);

            return null;
        }

        // Smart file type detection using MIME type, extension, and metadata
        $hasExif = function_exists('exif_imagetype') && false !== @exif_imagetype($filePath->getPath());
        $metadataHints = [
            'width' => $mediaMeta->getWidth(),
            'height' => $mediaMeta->getHeight(),
            'duration' => $mediaMeta->getDuration(),
        ];
        $fileType = FileType::detectSmart(
            $filePath->getPath(),
            $mimeType,
            $hasExif,
            $metadataHints
        );

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

        $this->logger->debug('File ingested successfully', [
            'file_path' => $filePath->getPath(),
            'file_type' => $fileType->value,
            'hash' => $fileHash->getHash(),
        ]);

        // Dispatch FileProcessed event
        $event = new FileProcessed($asset);
        $this->messageBus->dispatch($event);

        return $asset;
    }
}
