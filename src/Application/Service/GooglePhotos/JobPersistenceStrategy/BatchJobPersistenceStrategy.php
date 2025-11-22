<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos\JobPersistenceStrategy;

use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Exceptions\RepositoryException;
use SortingPhotosByDate\Exceptions\ValidationException;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryWritePort;

/**
 * Batch persistence strategy that accumulates jobs and saves them in batches.
 * Improves performance by reducing database round-trips.
 */
final class BatchJobPersistenceStrategy implements JobPersistenceStrategyInterface
{
    private const int BATCH_SIZE = 500;

    /**
     * @var UploadJob[]
     */
    private array $buffer = [];
    private readonly int $batchSize;

    public function __construct(
        private readonly UploadJobRepositoryWritePort $repository,
        private readonly LoggerPort $logger,
        int $batchSize = self::BATCH_SIZE,
    ) {
        if ($batchSize < 1) {
            throw ValidationException::positiveValue('Batch size');
        }
        $this->batchSize = $batchSize;
    }

    public function persist(UploadJob $job): void
    {
        $this->buffer[] = $job;

        // Flush when buffer reaches batch size
        if (\count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): int
    {
        if ([] === $this->buffer) {
            return 0;
        }

        $count = \count($this->buffer);
        $this->logger->debug('Flushing batch of jobs', [
            'batch_size' => $count,
        ]);

        try {
            $savedCount = $this->repository->saveBatch($this->buffer);
            $this->buffer = [];

            $this->logger->info('Batch flushed successfully', [
                'saved_count' => $savedCount,
                'batch_size' => $count,
            ]);

            return $savedCount;
        } catch (\Exception $e) {
            $this->logger->error('Failed to flush batch', [
                'batch_size' => $count,
                'error' => $e->getMessage(),
                'error_type' => $e::class,
                'trace' => $e->getTraceAsString(),
            ]);

            // Clear buffer even on error to prevent memory leak
            // Jobs will be lost, but this prevents infinite retry loops
            $this->buffer = [];

            // Re-throw to allow caller to handle the error
            throw RepositoryException::failedSaveBatch($count, $e->getMessage(), $e);
        }
    }

    public function supportsBatch(): bool
    {
        return true;
    }
}
