<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Domain\Event\BatchCompleted;
use SortingPhotosByDate\Domain\Event\UploadCompleted;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchItem;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\BatchItemRequest;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\GooglePhotosApiClientPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadBatchRepositoryPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class BatchProcessor
{
    private const int MAX_ITEMS_PER_REQUEST = 50;

    public function __construct(
        private GooglePhotosApiClientPort $apiClient,
        private UploadBatchRepositoryPort $batchRepository,
        private UploadJobRepositoryPort $jobRepository,
        private QuotaManager $quotaManager,
        private MessageBusInterface $messageBus,
        private LoggerPort $logger,
        private ?string $albumId = null,
    ) {
    }

    public function processBatch(UploadBatch $batch): void
    {
        // Only start processing if batch is not already processing
        if ($batch->getState()->value !== \SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchState::PROCESSING->value) {
            $batch = $batch->startProcessing();
            $this->batchRepository->save($batch);
        }

        // Get items to process (starting from currentIndex)
        $itemsToProcess = $batch->getItemsToProcess();

        // Split into groups of 50 (for large batches)
        $chunks = \array_chunk($itemsToProcess, self::MAX_ITEMS_PER_REQUEST);

        foreach ($chunks as $chunkIndex => $chunk) {
            try {
                // Check quota
                $this->quotaManager->checkQuota();

                // Create API requests
                $requests = [];
                /** @var BatchItem $item */
                foreach ($chunk as $item) {
                    $requests[] = new BatchItemRequest(
                        uploadToken: $item->getUploadToken(),
                        creationTime: $item->getCreationTime(),
                        filename: $item->getFilename(),
                        mimeType: $item->getMimeType()
                    );
                }

                // Create media items
                $response = $this->apiClient->batchCreateMediaItems(
                    items: $requests,
                    albumId: $this->albumId
                );

                $this->quotaManager->recordRequest();

                // Process each result individually
                $this->processBatchResponse($batch, $response, $chunkIndex);

            } catch (QuotaExceededException $e) {
                // Pause batch
                $batch = $batch->pause($e->getResetTime());
                $this->batchRepository->save($batch);

                $this->logger->warning('Batch paused due to quota', [
                    'batch_id' => $batch->getId()->getId(),
                    'reset_time' => $e->getResetTime()->format('Y-m-d H:i:s'),
                ]);

                throw $e;
            } catch (\Exception $e) {
                $this->logger->error('Batch processing error', [
                    'batch_id' => $batch->getId()->getId(),
                    'error' => $e->getMessage(),
                ]);

                // For 4xx errors - exponential backoff
                if ($e->getCode() >= 400 && $e->getCode() < 500) {
                    // Error will be handled by scheduler
                    throw $e;
                }

                // For other errors - mark batch as failed
                $batch = $batch->markAsFailed($e->getMessage());
                $this->batchRepository->save($batch);
                throw $e;
            }
        }

        // Check if all items are processed
        if ($batch->allItemsProcessed()) {
            $batch = $batch->complete();
            $this->batchRepository->save($batch);

            $successfulCount = \count(\array_filter($batch->getItems(), fn (BatchItem $item): bool => $item->isProcessed()));

            $this->messageBus->dispatch(new BatchCompleted(
                batchId: $batch->getId(),
                successfulItems: $successfulCount,
                totalItems: \count($batch->getItems())
            ));

            $this->logger->info('Batch completed', [
                'batch_id' => $batch->getId()->getId(),
                'successful_items' => $successfulCount,
                'total_items' => \count($batch->getItems()),
            ]);
        } else {
            $this->batchRepository->save($batch);
        }
    }

    private function processBatchResponse(
        UploadBatch $batch,
        \SortingPhotosByDate\Ports\Storage\GooglePhotos\BatchCreateResponse $response,
        int $chunkIndex,
    ): void {
        $globalStartIndex = $batch->getCurrentIndex() + ($chunkIndex * self::MAX_ITEMS_PER_REQUEST);

        // Process each result
        foreach ($response->getNewMediaItemResults() as $index => $result) {
            $globalIndex = $globalStartIndex + $index;
            $batchItem = $batch->getItems()[$globalIndex];
            $job = $this->jobRepository->findById($batchItem->getJobId());

            if (!$job instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob) {
                $this->logger->error('Job not found for batch item', [
                    'job_id' => $batchItem->getJobId()->getId(),
                    'batch_id' => $batch->getId()->getId(),
                ]);
                continue;
            }

            if ($result->getStatus()->isSuccess()) {
                // Success
                $mediaItem = $result->getMediaItem();
                if (null === $mediaItem) {
                    $this->logger->warning('Media item is null despite success status', [
                        'job_id' => $job->getId()->getId(),
                    ]);
                    continue;
                }

                // Only mark as completed if not already completed
                if ('completed' !== $job->getState()->value) {
                    $job = $job->markCompleted();
                    $this->jobRepository->save($job);
                }

                $batch = $batch->markItemProcessed($globalIndex);
                $this->batchRepository->save($batch);

                // Dispatch event
                // $this->messageBus->dispatch(new UploadCompleted(
                //     jobId: $job->getId(),
                //     filePath: $job->getFilePath()->getPath(),
                //     uploadedAt: new \DateTimeImmutable()
                // ));

                $this->logger->debug('Media item created successfully', [
                    'job_id' => $job->getId()->getId(),
                    'media_item_id' => $mediaItem->getId(),
                ]);
            } else {
                // Error for this item
                $this->handleItemError($job, $result->getStatus(), $batchItem, $batch, $globalIndex);
            }
        }

        // Process batch-level errors
        if ([] !== $response->getErrors()) {
            $this->handleBatchErrors($batch, $response->getErrors());
        }
    }

    private function handleItemError(
        \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob $job,
        \SortingPhotosByDate\Ports\Storage\GooglePhotos\Status $status,
        BatchItem $item,
        UploadBatch $batch,
        int $index,
    ): void {
        $code = $status->getCode();

        match (true) {
            // Temporary errors - mark for retry
            \in_array($code, [500, 503], true) => $this->markForRetry($job, $batch, $index),

            // Quota
            429 === $code => $this->pauseForQuota($batch, $index, $status->getMessage()),

            // Token errors - re-upload file
            \in_array($code, [400, 404], true) => $this->markTokenInvalid($job, $item, $batch, $index),

            // Critical errors
            default => $this->markAsFailed($job, $status->getMessage(), $batch, $index),
        };
    }

    private function markForRetry(
        \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob $job,
        UploadBatch $batch,
        int $index,
    ): void {
        $batch = $batch->markItemFailed($index, 'Temporary error, will retry');
        $this->batchRepository->save($batch);

        $this->logger->warning('Item marked for retry', [
            'job_id' => $job->getId()->getId(),
            'batch_id' => $batch->getId()->getId(),
        ]);
    }

    private function pauseForQuota(
        UploadBatch $batch,
        int $index,
        string $message,
    ): void {
        // Quota will be handled at batch level
        $batch = $batch->markItemFailed($index, $message);
        $this->batchRepository->save($batch);
    }

    private function markTokenInvalid(
        \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob $job,
        BatchItem $item,
        UploadBatch $batch,
        int $index,
    ): void {
        $this->logger->warning('Upload token invalid, re-uploading file', [
            'job_id' => $job->getId()->getId(),
            'token' => $item->getUploadToken(),
        ]);

        // Reset Job to UPLOADING state
        $job = $job->resetToUploading();
        $this->jobRepository->save($job);

        $batch = $batch->markItemFailed($index, 'Token invalid, file will be re-uploaded');
        $this->batchRepository->save($batch);
    }

    private function markAsFailed(
        \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob $job,
        string $error,
        UploadBatch $batch,
        int $index,
    ): void {
        $job = $job->markAsFailed($error);
        $this->jobRepository->save($job);

        $batch = $batch->markItemFailed($index, $error);
        $this->batchRepository->save($batch);

        $this->logger->error('Item marked as failed', [
            'job_id' => $job->getId()->getId(),
            'error' => $error,
        ]);
    }

    /**
     * @param array<\SortingPhotosByDate\Ports\Storage\GooglePhotos\Error> $errors
     */
    private function handleBatchErrors(UploadBatch $batch, array $errors): void
    {
        foreach ($errors as $error) {
            $this->logger->error('Batch error', [
                'batch_id' => $batch->getId()->getId(),
                'code' => $error->getCode(),
                'message' => $error->getMessage(),
                'domain' => $error->getDomain(),
            ]);
        }
    }
}
