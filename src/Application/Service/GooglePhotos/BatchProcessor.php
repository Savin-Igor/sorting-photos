<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use Carbon\Carbon;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Domain\Event\BatchCompleted;
use SortingPhotosByDate\Domain\Event\UploadCompleted;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchItem;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatch;
use SortingPhotosByDate\Infrastructure\Metadata\FilenameDateExtractor;
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
        private FilenameDateExtractor $filenameDateExtractor,
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

        $this->logger->debug('Processing batch', [
            'batch_id' => $batch->getId()->getId(),
            'current_index' => $batch->getCurrentIndex(),
            'total_items' => \count($batch->getItems()),
            'items_to_process' => \count($itemsToProcess),
        ]);

        // Short-circuit: nothing to process
        if ([] === $itemsToProcess) {
            if ($batch->allItemsProcessed()) {
                // Batch is effectively done but not marked yet
                if ($batch->getState()->value !== \SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchState::COMPLETED->value) {
                    $batch = $batch->complete();
                    $this->batchRepository->save($batch);
                    $successfulCount = \count(\array_filter($batch->getItems(), fn (BatchItem $item): bool => $item->isProcessed()));
                    $this->logger->info('Batch completed (nothing left to process)', [
                        'batch_id' => $batch->getId()->getId(),
                        'successful_items' => $successfulCount,
                        'total_items' => \count($batch->getItems()),
                    ]);
                }

                return;
            }

            // Stuck batch: no items left but not all processed (likely failed items)
            $failedItems = $batch->getFailedItems();
            $this->logger->warning('Stuck batch detected: no items to process, but not all processed. Marking as failed.', [
                'batch_id' => $batch->getId()->getId(),
                'failed_count' => \count($failedItems),
                'current_index' => $batch->getCurrentIndex(),
                'total_items' => \count($batch->getItems()),
            ]);
            $batch = $batch->markAsFailed('No items to process, but not all processed. Skipping batch.');
            $this->batchRepository->save($batch);

            return;
        }

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
                    // Check if date was extracted from filename before sending to Google Photos
                    $job = $this->jobRepository->findById($item->getJobId());
                    if ($job instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob) {
                        $filePath = $job->getFilePath()->getPath();
                        $filenameDate = $this->filenameDateExtractor->extract($filePath);
                        if ($filenameDate instanceof Carbon) {
                            $creationTime = $item->getCreationTime();
                            $creationTimeCarbon = Carbon::instance($creationTime);

                            // Compare dates: check if dates match (considering different formats)
                            // Some filename formats only have date without time (00:00:00), so we compare:
                            // 1. If filename date has time (not 00:00:00), compare with full precision (within 1 second)
                            // 2. If filename date has no time (00:00:00), compare only date part (year, month, day)
                            $filenameHasTime = !(0 === $filenameDate->hour && 0 === $filenameDate->minute && 0 === $filenameDate->second);

                            $datesMatch = false;
                            if ($filenameHasTime) {
                                // Full date-time comparison (allow 1 second difference)
                                $dateDiff = abs($creationTimeCarbon->diffInSeconds($filenameDate));
                                $datesMatch = $dateDiff <= 1;
                            } else {
                                // Date-only comparison (compare year, month, day)
                                $datesMatch = $creationTimeCarbon->year === $filenameDate->year
                                    && $creationTimeCarbon->month === $filenameDate->month
                                    && $creationTimeCarbon->day === $filenameDate->day;
                            }

                            if ($datesMatch) {
                                // Date matches filename date
                                $this->logger->info('Date extracted from filename before sending to Google Photos', [
                                    'file_path' => $filePath,
                                    'is_video' => $item->isVideo(),
                                    'creation_time' => $creationTime->format('Y-m-d H:i:s'),
                                    'filename_date' => $filenameDate->format('Y-m-d H:i:s'),
                                    'date_only_match' => !$filenameHasTime,
                                    'source' => 'filename',
                                ]);
                            }
                        }
                    }

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

                // Process each result individually - CRITICAL: batch is updated inside this method
                $batch = $this->processBatchResponse($batch, $response, $chunkIndex);

                // Log summary for this chunk
                $results = $response->getNewMediaItemResults();
                $errors = $response->getErrors();
                $successCount = 0;
                foreach ($results as $r) {
                    if ($r->getStatus()->isSuccess()) {
                        ++$successCount;
                    }
                }
                $failed = \count($results) - $successCount + \count($errors);
                $failedItems = [];
                foreach ($results as $idx => $r) {
                    if (!$r->getStatus()->isSuccess()) {
                        $filename = $chunk[$idx]->getFilename() ?? null;
                        $failedItems[] = [
                            'index' => $idx,
                            'filename' => $filename,
                            'code' => $r->getStatus()->getCode(),
                            'message' => $r->getStatus()->getMessage(),
                        ];
                    }
                }
                // Add batch-level errors too
                foreach ($errors as $e) {
                    $failedItems[] = [
                        'index' => null,
                        'filename' => null,
                        'code' => $e->getCode(),
                        'message' => $e->getMessage(),
                    ];
                }

                $this->logger->info('Batch chunk processed', [
                    'batch_id' => $batch->getId()->getId(),
                    'chunk_index' => $chunkIndex,
                    'chunk_size' => \count($chunk),
                    'success' => $successCount,
                    'failed' => $failed,
                    'failed_items' => \array_slice($failedItems, 0, 10),
                ]);

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
            // Check if batch should be marked as FAILED due to unsupported media files
            // If all items failed with unsupported format errors, mark batch as FAILED
            if ($this->shouldMarkBatchAsFailed($batch)) {
                $errorMessage = $this->getBatchFailureReason($batch);
                $batch = $batch->markAsFailed($errorMessage);
                $this->batchRepository->save($batch);

                $this->logger->error('Batch marked as FAILED due to unsupported media files', [
                    'batch_id' => $batch->getId()->getId(),
                    'error' => $errorMessage,
                    'total_items' => \count($batch->getItems()),
                ]);

                return; // Don't process further
            }

            // Only complete if batch is not already completed
            if ($batch->getState()->value !== \SortingPhotosByDate\Domain\Storage\GooglePhotos\BatchState::COMPLETED->value) {
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
                $this->logger->debug('Batch already completed, skipping completion', [
                    'batch_id' => $batch->getId()->getId(),
                ]);
            }
        } else {
            $this->batchRepository->save($batch);
        }
    }

    private function processBatchResponse(
        UploadBatch $batch,
        \SortingPhotosByDate\Ports\Storage\GooglePhotos\BatchCreateResponse $response,
        int $chunkIndex,
    ): UploadBatch {
        $globalStartIndex = $batch->getCurrentIndex() + ($chunkIndex * self::MAX_ITEMS_PER_REQUEST);

        // Process each result
        foreach ($response->getNewMediaItemResults() as $index => $result) {
            $globalIndex = $globalStartIndex + $index;

            // Validate index bounds to prevent array out of bounds
            if ($globalIndex >= \count($batch->getItems())) {
                $this->logger->error('Global index out of bounds', [
                    'global_index' => $globalIndex,
                    'items_count' => \count($batch->getItems()),
                    'batch_id' => $batch->getId()->getId(),
                    'chunk_index' => $chunkIndex,
                    'global_start_index' => $globalStartIndex,
                ]);
                continue;
            }

            $batchItem = $batch->getItems()[$globalIndex];
            $job = $this->jobRepository->findById($batchItem->getJobId());

            if (!$job instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob) {
                $this->logger->error('Job not found for batch item - marking item as failed', [
                    'job_id' => $batchItem->getJobId()->getId(),
                    'batch_id' => $batch->getId()->getId(),
                    'filename' => $batchItem->getFilename(),
                ]);
                // Mark batch item as failed so batch can complete
                $batch = $batch->markItemFailed($globalIndex, 'Job not found in database (may have been deleted)');
                $this->batchRepository->save($batch);
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
                // Ensure proper state transition: UPLOADED -> IN_BATCH -> COMPLETED
                if ('completed' !== $job->getState()->value) {
                    // If job is UPLOADED but not yet IN_BATCH, transition to IN_BATCH first
                    if ('uploaded' === $job->getState()->value) {
                        if (!$job->getBatchId() instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadBatchId) {
                            $job = $job->assignToBatch($batch->getId());
                            $this->jobRepository->save($job);
                        } elseif ($job->getBatchId()->getId() !== $batch->getId()->getId()) {
                            // Job belongs to different batch - this shouldn't happen, but handle gracefully
                            $this->logger->warning('Job belongs to different batch', [
                                'job_id' => $job->getId()->getId(),
                                'job_batch_id' => $job->getBatchId()->getId(),
                                'current_batch_id' => $batch->getId()->getId(),
                            ]);
                            continue;
                        }
                    }
                    // Now mark as completed (only valid from IN_BATCH state)
                    // Check that job is in IN_BATCH state and belongs to this batch
                    if ('in_batch' === $job->getState()->value && $job->getBatchId()?->getId() === $batch->getId()->getId()) {
                        $job = $job->markCompleted();
                        $this->jobRepository->save($job);
                    } elseif ('completed' !== $job->getState()->value) {
                        $this->logger->warning('Cannot mark job as completed from current state', [
                            'job_id' => $job->getId()->getId(),
                            'current_state' => $job->getState()->value,
                            'job_batch_id' => $job->getBatchId()?->getId(),
                            'batch_id' => $batch->getId()->getId(),
                        ]);
                    }
                }

                // CRITICAL: Update batch object after marking item as processed
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
                    'global_index' => $globalIndex,
                    'current_index' => $batch->getCurrentIndex(),
                ]);
            } else {
                // Error for this item - update batch with error handling
                $batch = $this->handleItemError($job, $result->getStatus(), $batchItem, $batch, $globalIndex);
            }
        }

        // Process batch-level errors
        if ([] !== $response->getErrors()) {
            $batch = $this->handleBatchErrors($batch, $response->getErrors());
        }

        return $batch;
    }

    private function handleItemError(
        \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob $job,
        \SortingPhotosByDate\Ports\Storage\GooglePhotos\Status $status,
        BatchItem $item,
        UploadBatch $batch,
        int $index,
    ): UploadBatch {
        $code = $status->getCode();
        $message = $status->getMessage();

        // Log error details for debugging
        $this->logger->warning('Item error detected', [
            'job_id' => $job->getId()->getId(),
            'code' => $code,
            'message' => $message,
            'file_path' => $job->getFilePath()->getPath(),
            'upload_token' => $item->getUploadToken(),
            'file_size' => $job->getFileSize(),
            'mime_type' => $job->getMimeType(),
        ]);

        return match (true) {
            // Temporary errors - mark for retry (including code 3 - INTERNAL_ERROR)
            \in_array($code, [3, 500, 503], true) => $this->markForRetry($job, $batch, $index),

            // Quota
            429 === $code => $this->pauseForQuota($batch, $index, $message),

            // Token errors - re-upload file
            \in_array($code, [400, 404], true) => $this->markTokenInvalid($job, $item, $batch, $index),

            // Critical errors
            default => $this->markAsFailed($job, $message, $batch, $index),
        };
    }

    private function markForRetry(
        \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob $job,
        UploadBatch $batch,
        int $index,
    ): UploadBatch {
        $batch = $batch->markItemFailed($index, 'Temporary error, will retry');
        $this->batchRepository->save($batch);

        $this->logger->warning('Item marked for retry', [
            'job_id' => $job->getId()->getId(),
            'batch_id' => $batch->getId()->getId(),
        ]);

        return $batch;
    }

    private function pauseForQuota(
        UploadBatch $batch,
        int $index,
        string $message,
    ): UploadBatch {
        // Quota will be handled at batch level
        $batch = $batch->markItemFailed($index, $message);
        $this->batchRepository->save($batch);

        return $batch;
    }

    private function markTokenInvalid(
        \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob $job,
        BatchItem $item,
        UploadBatch $batch,
        int $index,
    ): UploadBatch {
        $this->logger->warning('Upload token invalid, re-uploading file', [
            'job_id' => $job->getId()->getId(),
            'token' => $item->getUploadToken(),
        ]);

        // Reset Job to UPLOADING state
        $job = $job->resetToUploading();
        $this->jobRepository->save($job);

        $batch = $batch->markItemFailed($index, 'Token invalid, file will be re-uploaded');
        $this->batchRepository->save($batch);

        return $batch;
    }

    private function markAsFailed(
        \SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob $job,
        string $error,
        UploadBatch $batch,
        int $index,
    ): UploadBatch {
        $job = $job->markAsFailed($error);
        $this->jobRepository->save($job);

        $batch = $batch->markItemFailed($index, $error);
        $this->batchRepository->save($batch);

        $this->logger->error('Item marked as failed', [
            'job_id' => $job->getId()->getId(),
            'error' => $error,
        ]);

        return $batch;
    }

    /**
     * @param array<\SortingPhotosByDate\Ports\Storage\GooglePhotos\Error> $errors
     */
    private function handleBatchErrors(UploadBatch $batch, array $errors): UploadBatch
    {
        foreach ($errors as $error) {
            $this->logger->error('Batch error', [
                'batch_id' => $batch->getId()->getId(),
                'code' => $error->getCode(),
                'message' => $error->getMessage(),
                'domain' => $error->getDomain(),
            ]);
        }

        // If batch-level errors indicate unsupported media, mark batch as FAILED
        $unsupportedError = $this->hasUnsupportedMediaError($errors);
        if (null !== $unsupportedError) {
            $batch = $batch->markAsFailed($unsupportedError);
            $this->batchRepository->save($batch);

            $this->logger->error('Batch marked as FAILED due to batch-level unsupported media error', [
                'batch_id' => $batch->getId()->getId(),
                'error' => $unsupportedError,
            ]);
        }

        return $batch;
    }

    /**
     * Check if batch should be marked as FAILED due to unsupported media files.
     * A batch should be marked as FAILED if all items failed with unsupported format errors.
     */
    private function shouldMarkBatchAsFailed(UploadBatch $batch): bool
    {
        $items = $batch->getItems();
        if ([] === $items) {
            return false;
        }

        $allFailed = true;
        $allUnsupported = true;

        foreach ($items as $item) {
            if ($item->isProcessed()) {
                $allFailed = false;
                break;
            }

            $error = $item->getError();
            if (null === $error) {
                $allFailed = false;
                break;
            }

            // Check if error indicates unsupported media format
            if (!$this->isUnsupportedMediaError($error)) {
                $allUnsupported = false;
            }
        }

        // Mark as FAILED only if all items failed AND all failures are due to unsupported media
        return $allFailed && $allUnsupported;
    }

    /**
     * Check if error message indicates unsupported media format.
     */
    private function isUnsupportedMediaError(string $error): bool
    {
        $errorLower = \strtolower($error);
        $unsupportedPatterns = [
            'unsupported',
            'invalid.*format',
            'invalid.*media',
            'media.*type.*not.*supported',
            'file.*format.*not.*supported',
            'invalid.*mime.*type',
            'bad.*request',
        ];

        foreach ($unsupportedPatterns as $pattern) {
            if (\preg_match('/'.$pattern.'/i', $errorLower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if batch-level errors indicate unsupported media.
     *
     * @param array<\SortingPhotosByDate\Ports\Storage\GooglePhotos\Error> $errors
     */
    private function hasUnsupportedMediaError(array $errors): ?string
    {
        foreach ($errors as $error) {
            $message = $error->getMessage();
            $code = $error->getCode();

            // Check for unsupported media errors (code 400 or specific error messages)
            if (400 === $code || $this->isUnsupportedMediaError($message)) {
                return \sprintf('Unsupported media format: %s (code: %d)', $message, $code);
            }
        }

        return null;
    }

    /**
     * Get failure reason for batch.
     */
    private function getBatchFailureReason(UploadBatch $batch): string
    {
        $items = $batch->getItems();
        $errorMessages = [];

        foreach ($items as $item) {
            $error = $item->getError();
            if (null !== $error && $this->isUnsupportedMediaError($error)) {
                $errorMessages[] = $error;
            }
        }

        if ([] !== $errorMessages) {
            // Get unique error messages
            $uniqueErrors = \array_unique($errorMessages);
            $reason = \implode('; ', \array_slice($uniqueErrors, 0, 3));
            if (\count($uniqueErrors) > 3) {
                $reason .= ' ...';
            }

            return \sprintf('All items failed with unsupported media format errors: %s', $reason);
        }

        return 'All items failed with unsupported media format errors';
    }
}
