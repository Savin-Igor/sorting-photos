<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\OffsetMismatchException;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\SessionExpiredException;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\ImageCompressor;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\VideoCompressor;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\GooglePhotosApiClientPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;

final readonly class ResumableUploadService
{
    private const int CHUNK_SIZE = 256 * 1024; // 256 KB
    private const int CHECKPOINT_INTERVAL = 10 * 1024 * 1024; // 10 MB

    public function __construct(
        private GooglePhotosApiClientPort $apiClient,
        private UploadJobRepositoryPort $jobRepository,
        private QuotaManager $quotaManager,
        private ImageCompressor $imageCompressor,
        private VideoCompressor $videoCompressor,
        private LoggerPort $logger,
    ) {
    }

    public function uploadFile(UploadJob $job): void
    {
        $this->logger->info('Starting file upload', [
            'job_id' => $job->getId(),
            'file_path' => $job->getFilePath()->getPath(),
            'file_size' => $job->getFileSize(),
            'mime_type' => $job->getMimeType(),
            'state' => $job->getState()->value,
        ]);

        // 1. Check quota
        try {
            $this->quotaManager->checkQuota();
            $this->logger->debug('Quota check passed');
        } catch (\Exception $e) {
            $this->logger->error('Quota check failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        // 2. Check session expiration
        $now = new \DateTimeImmutable();
        if ($job->needsSessionRenewal($now)) {
            $this->logger->info('Session expired, renewing');
            $this->handleExpiredSession($job);
            $job = $this->jobRepository->findById($job->getId());
            if (!$job instanceof UploadJob) {
                throw new \RuntimeException('Job not found after session expiration handling');
            }
        }

        // 3. If session exists - resume
        if ($job->getResumableSession() instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\ResumableSession) {
            $this->logger->info('Resuming existing upload session');
            $this->resumeUpload($job);

            return;
        }

        // 4. Compress file if needed
        $this->logger->debug('Checking if compression needed');
        $filePath = $this->compressIfNeeded($job);
        $this->logger->debug('File path determined', ['file_path' => $filePath]);

        if (!\file_exists($filePath)) {
            throw new \RuntimeException(\sprintf('File does not exist: %s', $filePath));
        }

        $fileSize = \filesize($filePath);
        if (false === $fileSize) {
            throw new \RuntimeException(\sprintf('Failed to get file size: %s', $filePath));
        }
        $this->logger->info('File size determined', ['file_size' => $fileSize]);

        // 5. Initialize new session
        $this->logger->info('Initiating resumable upload session', [
            'file_size' => $fileSize,
            'mime_type' => $job->getMimeType(),
        ]);
        $sessionUri = $this->apiClient->initiateResumableUpload(
            fileSize: $fileSize,
            mimeType: $job->getMimeType()
        );
        $this->logger->info('Resumable upload session created', ['session_uri' => $sessionUri]);

        $job = $job->startUpload($sessionUri);
        $this->jobRepository->save($job);

        // Dispatch event (temporarily disabled - no handler registered)
        // $this->messageBus->dispatch(new UploadInitiated(
        //     jobId: $job->getId(),
        //     filePath: $job->getFilePath()->getPath()
        // ));
        $this->logger->info('Upload initiated', [
            'job_id' => $job->getId(),
            'file_path' => $job->getFilePath()->getPath(),
        ]);

        // 6. Upload in chunks
        $handle = \fopen($filePath, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Failed to open file: %s', $filePath));
        }

        try {
            $offset = 0;

            while ($offset < $fileSize) {
                // Check quota before each chunk
                try {
                    $this->quotaManager->checkQuota();
                } catch (QuotaExceededException $e) {
                    $job = $job->pauseByQuota();
                    $this->jobRepository->save($job);
                    throw $e;
                }

                // Calculate chunk size - must be exactly CHUNK_SIZE except for last chunk
                $remainingBytes = $fileSize - $offset;

                // All chunks except the last one must be exactly CHUNK_SIZE (262144 bytes)
                // Last chunk can be any size
                if ($remainingBytes <= self::CHUNK_SIZE) {
                    // This is the last chunk - can be any size
                    $chunkSize = $remainingBytes;
                } else {
                    // Not the last chunk - must be exactly CHUNK_SIZE
                    $chunkSize = self::CHUNK_SIZE;
                }

                // Read chunk
                \fseek($handle, $offset);
                $chunk = \fread($handle, $chunkSize);
                if (false === $chunk) {
                    throw new \RuntimeException(\sprintf('Failed to read chunk at offset %d', $offset));
                }

                // Upload chunk
                $this->apiClient->uploadChunk(
                    sessionUri: $sessionUri,
                    chunk: $chunk,
                    offset: $offset,
                    totalSize: $fileSize
                );

                $this->quotaManager->recordBytesUploaded(\strlen($chunk));

                $offset += \strlen($chunk);
                $job = $job->updateProgress($offset);
                $this->jobRepository->save($job);

                // Dispatch progress event (temporarily disabled - no handler registered)
                // $this->messageBus->dispatch(new UploadProgressed(
                //     jobId: $job->getId(),
                //     uploadedBytes: $offset,
                //     totalBytes: $fileSize
                // ));
                $this->logger->debug('Upload progress', [
                    'job_id' => $job->getId(),
                    'uploaded_bytes' => $offset,
                    'total_bytes' => $fileSize,
                ]);

                // Checkpoint
                if (0 === $offset % self::CHECKPOINT_INTERVAL) {
                    $this->logger->debug('Upload checkpoint', [
                        'job_id' => $job->getId()->getId(),
                        'uploaded_bytes' => $offset,
                        'total_bytes' => $fileSize,
                        'progress_percent' => ($offset / $fileSize) * 100,
                    ]);
                }
            }
        } finally {
            \fclose($handle);
        }

        // 7. Get uploadToken
        $uploadToken = $this->apiClient->completeUpload($sessionUri);
        $job = $job->completeUpload($uploadToken);
        $this->jobRepository->save($job);

        $this->logger->info('File upload completed', [
            'job_id' => $job->getId()->getId(),
            'file_path' => $job->getFilePath()->getPath(),
            'file_size' => $fileSize,
        ]);
    }

    private function handleExpiredSession(UploadJob $job): void
    {
        $session = $job->getResumableSession();
        if (!$session instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\ResumableSession) {
            return;
        }

        $this->logger->warning('Resumable session expired, checking status', [
            'job_id' => $job->getId()->getId(),
            'uploaded_bytes' => $session->getUploadedBytes(),
        ]);

        try {
            // Try to query status
            $status = $this->apiClient->queryUploadStatus($session->getSessionUri());

            if ($status->isComplete()) {
                // File already uploaded
                $uploadToken = $status->getUploadToken();
                if (null === $uploadToken) {
                    throw new \RuntimeException('Upload complete but no token received');
                }

                $job = $job->completeUpload($uploadToken);
                $this->jobRepository->save($job);

                $this->logger->info('File was already uploaded, token retrieved', [
                    'job_id' => $job->getId()->getId(),
                ]);
            } else {
                // Session expired, but file not uploaded
                $job = $job->markSessionExpired($status->getUploadedBytes());
                $this->jobRepository->save($job);

                $this->logger->warning('Session expired, file not uploaded', [
                    'job_id' => $job->getId()->getId(),
                    'last_known_bytes' => $status->getUploadedBytes(),
                ]);
            }
        } catch (SessionExpiredException) {
            // Session definitely expired
            $job = $job->markSessionExpired(0);
            $this->jobRepository->save($job);

            $this->logger->warning('Session expired (404/410)', [
                'job_id' => $job->getId()->getId(),
            ]);
        }
    }

    private function resumeUpload(UploadJob $job): void
    {
        $session = $job->getResumableSession();
        if (!$session instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\ResumableSession) {
            throw new \RuntimeException('Cannot resume: no resumable session');
        }

        $filePath = $job->getFilePath()->getPath();
        $fileSize = \filesize($filePath);
        if (false === $fileSize) {
            throw new \RuntimeException(\sprintf('Failed to get file size: %s', $filePath));
        }

        $offset = $session->getUploadedBytes();

        // Query server for actual upload status to get correct offset
        $this->logger->info('Querying upload status from server', [
            'session_uri' => $session->getSessionUri(),
            'stored_offset' => $offset,
        ]);
        try {
            $uploadStatus = $this->apiClient->queryUploadStatus($session->getSessionUri());
            $serverOffset = $uploadStatus->getUploadedBytes();
            $this->logger->info('Server upload status', [
                'server_offset' => $serverOffset,
                'stored_offset' => $offset,
            ]);

            // Use server offset if it's different (server is authoritative)
            if ($serverOffset !== $offset) {
                $this->logger->warning('Offset mismatch, using server offset', [
                    'stored_offset' => $offset,
                    'server_offset' => $serverOffset,
                ]);
                $offset = $serverOffset;
                // Update job with correct offset
                $job = $job->updateProgress($offset);
                $this->jobRepository->save($job);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to query upload status, using stored offset', [
                'error' => $e->getMessage(),
                'offset' => $offset,
            ]);
            // Continue with stored offset if query fails
        }

        $this->logger->info('Resuming upload', [
            'job_id' => $job->getId()->getId(),
            'resume_offset' => $offset,
            'total_bytes' => $fileSize,
        ]);

        $handle = \fopen($filePath, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Failed to open file: %s', $filePath));
        }

        try {
            // Ensure offset is aligned to CHUNK_SIZE boundary (except for the very start and last chunk)
            // This is required by Google Photos API for resumable uploads
            $remainingBytes = $fileSize - $offset;
            if ($offset > 0 && 0 !== $offset % self::CHUNK_SIZE && $remainingBytes > self::CHUNK_SIZE) {
                // Round down to nearest CHUNK_SIZE boundary only if not the last chunk
                // Last chunk can start at any offset
                $alignedOffset = (int) (\floor($offset / self::CHUNK_SIZE) * self::CHUNK_SIZE);
                $this->logger->warning('Offset not aligned to chunk boundary, adjusting', [
                    'original_offset' => $offset,
                    'aligned_offset' => $alignedOffset,
                    'remaining_bytes' => $remainingBytes,
                ]);
                $offset = $alignedOffset;
                // Update job with aligned offset
                $job = $job->updateProgress($offset);
                $this->jobRepository->save($job);
            }

            while ($offset < $fileSize) {
                // Check quota
                try {
                    $this->quotaManager->checkQuota();
                } catch (QuotaExceededException $e) {
                    $job = $job->pauseByQuota();
                    $this->jobRepository->save($job);
                    throw $e;
                }

                // Calculate chunk size - must be exactly CHUNK_SIZE except for last chunk
                $remainingBytes = $fileSize - $offset;

                // All chunks except the last one must be exactly CHUNK_SIZE (262144 bytes)
                // Last chunk can be any size
                if ($remainingBytes <= self::CHUNK_SIZE) {
                    // This is the last chunk - can be any size
                    $chunkSize = $remainingBytes;
                } else {
                    // Not the last chunk - must be exactly CHUNK_SIZE
                    $chunkSize = self::CHUNK_SIZE;
                }

                // Read chunk
                \fseek($handle, $offset);
                $chunk = \fread($handle, $chunkSize);
                if (false === $chunk) {
                    throw new \RuntimeException(\sprintf('Failed to read chunk at offset %d', $offset));
                }

                // Upload chunk
                try {
                    $this->apiClient->uploadChunk(
                        sessionUri: $session->getSessionUri(),
                        chunk: $chunk,
                        offset: $offset,
                        totalSize: $fileSize
                    );
                } catch (OffsetMismatchException $e) {
                    // Server expects different offset - update and retry
                    $expectedOffset = $e->getExpectedOffset();
                    $this->logger->warning('Offset mismatch detected, updating offset', [
                        'current_offset' => $offset,
                        'expected_offset' => $expectedOffset,
                    ]);
                    $offset = $expectedOffset;
                    // Ensure offset is aligned to CHUNK_SIZE boundary
                    if (0 !== $offset % self::CHUNK_SIZE) {
                        $offset = (int) (\floor($offset / self::CHUNK_SIZE) * self::CHUNK_SIZE);
                    }
                    $job = $job->updateProgress($offset);
                    $this->jobRepository->save($job);
                    // Continue loop to retry with correct offset
                    continue;
                }

                $this->quotaManager->recordBytesUploaded(\strlen($chunk));

                $offset += \strlen($chunk);
                $job = $job->updateProgress($offset);
                $this->jobRepository->save($job);

                // Dispatch progress event (temporarily disabled - no handler registered)
                // $this->messageBus->dispatch(new UploadProgressed(
                //     jobId: $job->getId(),
                //     uploadedBytes: $offset,
                //     totalBytes: $fileSize
                // ));
                $this->logger->debug('Upload progress', [
                    'job_id' => $job->getId(),
                    'uploaded_bytes' => $offset,
                    'total_bytes' => $fileSize,
                ]);
            }
        } finally {
            \fclose($handle);
        }

        // Get token
        $uploadToken = $this->apiClient->completeUpload($session->getSessionUri());
        $job = $job->completeUpload($uploadToken);
        $this->jobRepository->save($job);

        $this->logger->info('Resumed upload completed', [
            'job_id' => $job->getId()->getId(),
        ]);
    }

    private function compressIfNeeded(UploadJob $job): string
    {
        $filePath = $job->getFilePath()->getPath();

        if ($job->isVideo()) {
            return $this->videoCompressor->compressIfNeeded($filePath);
        }

        return $this->imageCompressor->compressIfNeeded($filePath, $job->getMimeType());
    }
}
