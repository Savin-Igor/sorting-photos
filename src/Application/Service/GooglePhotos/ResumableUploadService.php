<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\OffsetMismatchException;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\ServiceUnavailableException;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\SessionExpiredException;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\ExifDateSetter;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\ImageCompressor;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\TokenManager;
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
        private ExifDateSetter $exifDateSetter,
        private LoggerPort $logger,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private TokenManager $tokenManager,
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

        // 3. If session exists - resume (but check file size first)
        if ($job->getResumableSession() instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\ResumableSession) {
            // Check if file is smaller than 2*CHUNK_SIZE - if so, delete session and use raw upload
            $filePath = $this->compressIfNeeded($job);
            $fileSize = \filesize($filePath);
            if (false === $fileSize) {
                throw new \RuntimeException(\sprintf('Failed to get file size: %s', $filePath));
            }
            
            // CRITICAL: Log file size check before deciding on upload method
            $threshold = 2 * self::CHUNK_SIZE;
            $this->logger->info('File size check with existing session', [
                'file_path' => $filePath,
                'file_name' => \basename($filePath),
                'file_size' => $fileSize,
                'file_size_kb' => \round($fileSize / 1024, 2),
                'threshold' => $threshold,
                'threshold_kb' => \round($threshold / 1024, 2),
                'should_use_raw_upload' => $fileSize < $threshold,
                'job_file_size' => $job->getFileSize(),
                'job_file_size_kb' => \round($job->getFileSize() / 1024, 2),
                'has_session' => true,
            ]);
            
            // CRITICAL: Files smaller than 2*CHUNK_SIZE (512 KB) MUST use raw upload, not resumable
            if ($fileSize < 2 * self::CHUNK_SIZE) {
                $this->logger->info('File smaller than 2*CHUNK_SIZE, deleting resumable session and using raw upload', [
                    'file_size' => $fileSize,
                    'file_size_kb' => \round($fileSize / 1024, 2),
                    'threshold' => $threshold,
                    'threshold_kb' => \round($threshold / 1024, 2),
                ]);
                // Expire session to force raw upload
                $lastKnownBytes = 0;
                $session = $job->getResumableSession();
                if ($session instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\ResumableSession) {
                    $lastKnownBytes = $session->getUploadedBytes();
                }
                $job = $job->markSessionExpired($lastKnownBytes);
                $this->jobRepository->save($job);
                // Continue to raw upload logic below - DO NOT return here
            } else {
                // File is large enough for resumable upload
                $this->logger->info('Resuming existing upload session for large file', [
                    'file_size' => $fileSize,
                    'file_size_kb' => \round($fileSize / 1024, 2),
                    'threshold' => $threshold,
                    'threshold_kb' => \round($threshold / 1024, 2),
                ]);
                $this->resumeUpload($job);

                return;
            }
        }

        // 4. Compress file if needed
        $this->logger->debug('Checking if compression needed');
        $filePath = $this->compressIfNeeded($job);
        $this->logger->debug('File path determined', ['file_path' => $filePath]);

        // 5. Set EXIF creation time if missing
        $this->logger->debug('Setting EXIF creation time if missing');
        $this->exifDateSetter->setCreationTime($filePath, $job->getCreationTime());

        if (!\file_exists($filePath)) {
            throw new \RuntimeException(\sprintf('File does not exist: %s', $filePath));
        }

        $fileSize = \filesize($filePath);
        if (false === $fileSize) {
            throw new \RuntimeException(\sprintf('Failed to get file size: %s', $filePath));
        }

        // CRITICAL: Log file size and threshold for debugging
        $threshold = 2 * self::CHUNK_SIZE;
        $this->logger->info('File size check', [
            'file_path' => $filePath,
            'file_name' => \basename($filePath),
            'file_size' => $fileSize,
            'file_size_kb' => \round($fileSize / 1024, 2),
            'threshold' => $threshold,
            'threshold_kb' => \round($threshold / 1024, 2),
            'should_use_raw_upload' => $fileSize < $threshold,
            'job_file_size' => $job->getFileSize(),
            'job_file_size_kb' => \round($job->getFileSize() / 1024, 2),
        ]);

        // For files smaller than CHUNK_SIZE, use raw upload (single POST)
        // Also use raw upload for files between CHUNK_SIZE and 2*CHUNK_SIZE to avoid chunk size issues
        if ($fileSize < 2 * self::CHUNK_SIZE) {
            $this->logger->info('File smaller than 2*CHUNK_SIZE, using raw upload', [
                'file_size' => $fileSize,
                'chunk_size' => self::CHUNK_SIZE,
                'threshold' => 2 * self::CHUNK_SIZE,
            ]);

            // Read entire file
            $fileContent = \file_get_contents($filePath);
            if (false === $fileContent) {
                throw new \RuntimeException(\sprintf('Failed to read file: %s', $filePath));
            }

            // Raw upload - POST entire file in one request
            $url = 'https://photoslibrary.googleapis.com/v1/uploads';
            $request = $this->requestFactory->createRequest('POST', $url)
                ->withHeader('Authorization', 'Bearer '.$this->tokenManager->getValidAccessToken())
                ->withHeader('X-Goog-Upload-Protocol', 'raw')
                ->withHeader('X-Goog-Upload-Content-Type', $job->getMimeType())
                ->withHeader('Content-Type', $job->getMimeType())
                ->withHeader('Content-Length', (string) $fileSize)
                ->withBody($this->streamFactory->createStream($fileContent));

            $response = $this->httpClient->sendRequest($request);

            // Handle quota errors
            if (429 === $response->getStatusCode()) {
                $resetTime = $this->extractQuotaResetTime($response);
                throw QuotaExceededException::requestsExceeded($resetTime);
            }

            if (200 !== $response->getStatusCode()) {
                $body = $response->getBody()->getContents();
                throw new \RuntimeException(\sprintf('Failed to upload file: %s %s', $response->getStatusCode(), $body));
            }

            $uploadToken = $response->getBody()->getContents();

            // For raw upload, we need to transition through uploading state
            // Create a virtual session URI for state transition
            $virtualSessionUri = 'raw://'.$job->getId()->getId();
            $job = $job->startUpload($virtualSessionUri);
            $this->jobRepository->save($job);

            // Now complete the upload
            $job = $job->completeUpload($uploadToken);
            $this->jobRepository->save($job);

            $this->logger->info('Raw upload completed', [
                'job_id' => $job->getId()->getId(),
                'file_path' => $job->getFilePath()->getPath(),
            ]);

            return;
        }

        // 5. Initialize new session
        // CRITICAL: This should only happen for files >= 512 KB
        $this->logger->info('Initiating resumable upload session for LARGE file', [
            'file_path' => $filePath,
            'file_name' => \basename($filePath),
            'file_size' => $fileSize,
            'file_size_kb' => \round($fileSize / 1024, 2),
            'threshold' => 2 * self::CHUNK_SIZE,
            'threshold_kb' => \round((2 * self::CHUNK_SIZE) / 1024, 2),
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
                    // This is the last chunk - send all remaining bytes
                    $chunkSize = $remainingBytes;
                    $this->logger->debug('Last chunk detected', [
                        'offset' => $offset,
                        'file_size' => $fileSize,
                        'chunk_size' => $chunkSize,
                    ]);
                } else {
                    // Not the last chunk - must be exactly CHUNK_SIZE
                    $chunkSize = self::CHUNK_SIZE;
                }

                $this->logger->debug('Chunk calculation', [
                    'offset' => $offset,
                    'file_size' => $fileSize,
                    'remaining_bytes' => $remainingBytes,
                    'chunk_size' => $chunkSize,
                ]);

                if ($chunkSize > 0) {
                    // Read chunk - ensure we read exactly chunkSize bytes
                    \fseek($handle, $offset);
                    $chunk = '';
                    $bytesRead = 0;
                    while ($bytesRead < $chunkSize) {
                        $data = \fread($handle, $chunkSize - $bytesRead);
                        if (false === $data || '' === $data) {
                            if (0 === $bytesRead) {
                                throw new \RuntimeException(\sprintf('Failed to read chunk at offset %d', $offset));
                            }
                            break; // EOF reached
                        }
                        $chunk .= $data;
                        $bytesRead += \strlen($data);
                    }

                    // Verify we read the expected amount (or reached EOF)
                    $actualChunkSize = \strlen($chunk);

                    // CRITICAL: For non-last chunks, we MUST send exactly CHUNK_SIZE bytes
                    // If we read less than CHUNK_SIZE and it's not the last chunk, this is an error
                    if ($actualChunkSize !== $chunkSize) {
                        if ($offset + $actualChunkSize < $fileSize) {
                            // Not EOF, but didn't read full chunk - this is an error
                            throw new \RuntimeException(\sprintf('Failed to read full chunk: expected %d bytes, got %d at offset %d (file size: %d)', $chunkSize, $actualChunkSize, $offset, $fileSize));
                        }
                        // If we're at EOF, it's OK - this is the last chunk
                    }

                    $this->logger->debug('Chunk read', [
                        'offset' => $offset,
                        'expected_size' => $chunkSize,
                        'actual_size' => $actualChunkSize,
                        'file_size' => $fileSize,
                        'is_last_chunk' => $offset + $actualChunkSize >= $fileSize,
                    ]);

                    // CRITICAL: Don't send chunks that are not exactly CHUNK_SIZE unless it's the last chunk
                    $isLastChunk = $offset + $actualChunkSize >= $fileSize;
                    if (self::CHUNK_SIZE !== $actualChunkSize && !$isLastChunk) {
                        $this->logger->error('CRITICAL: Attempting to send non-full chunk for non-last chunk', [
                            'file_path' => $filePath,
                            'file_name' => \basename($filePath),
                            'file_size' => $fileSize,
                            'offset' => $offset,
                            'actual_chunk_size' => $actualChunkSize,
                            'expected_chunk_size' => self::CHUNK_SIZE,
                            'is_last_chunk' => $isLastChunk,
                            'remaining_bytes' => $fileSize - $offset,
                        ]);
                        throw new \RuntimeException(\sprintf('Cannot send non-full chunk: got %d bytes, expected %d at offset %d (file size: %d, is_last: %s)', $actualChunkSize, self::CHUNK_SIZE, $offset, $fileSize, $isLastChunk ? 'yes' : 'no'));
                    }

                    // Upload chunk
                    $this->logger->debug('Uploading chunk', [
                        'file_name' => \basename($filePath),
                        'offset' => $offset,
                        'chunk_size' => $actualChunkSize,
                        'file_size' => $fileSize,
                        'is_last_chunk' => $isLastChunk,
                    ]);
                    $this->apiClient->uploadChunk(
                        sessionUri: $sessionUri,
                        chunk: $chunk,
                        offset: $offset,
                        totalSize: $fileSize
                    );

                    $this->quotaManager->recordBytesUploaded($actualChunkSize);
                    $offset += $actualChunkSize;
                } else {
                    // No more bytes to read
                    break;
                }

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

        $filePath = $this->compressIfNeeded($job);

        // Set EXIF creation time if missing
        $this->exifDateSetter->setCreationTime($filePath, $job->getCreationTime());

        $fileSize = \filesize($filePath);
        if (false === $fileSize) {
            throw new \RuntimeException(\sprintf('Failed to get file size: %s', $filePath));
        }

        // CRITICAL: Check if file is smaller than 2*CHUNK_SIZE - if so, expire session and use raw upload
        // This should not happen if uploadFile logic is correct, but double-check for safety
        if ($fileSize < 2 * self::CHUNK_SIZE) {
            $this->logger->warning('File smaller than 2*CHUNK_SIZE during resume, expiring session and using raw upload', [
                'file_size' => $fileSize,
                'threshold' => 2 * self::CHUNK_SIZE,
            ]);
            $lastKnownBytes = $session->getUploadedBytes();
            $job = $job->markSessionExpired($lastKnownBytes);
            $this->jobRepository->save($job);
            // Recursively call uploadFile to use raw upload
            $this->uploadFile($job);

            return;
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
        } catch (ServiceUnavailableException $e) {
            $this->logger->warning('Google Photos API is temporarily unavailable, pausing job.', [
                'job_id' => $job->getId()->getId(),
                'error' => $e->getMessage(),
            ]);
            // Pause the job, the orchestrator will pick it up later
            $job = $job->pause();
            $this->jobRepository->save($job);

            return; // Exit the upload process for this job
        } catch (\Exception $e) {
            $this->logger->warning('Failed to query upload status, using stored offset', [
                'error' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'offset' => $offset,
            ]);
            // If we cannot determine the exact number of bytes uploaded,
            // we must pause the job to avoid corruption or infinite loops.
            $job = $job->pause();
            $this->jobRepository->save($job);

            return;
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
            // Google Photos API requires all chunks (except last) to be exactly CHUNK_SIZE
            // If offset is not aligned to CHUNK_SIZE boundary, we need to align it
            // Server ignores duplicate bytes (confirmed via Range header), so it's safe to re-upload
            if ($offset > 0 && 0 !== $offset % self::CHUNK_SIZE) {
                $alignedOffset = (int) (\floor($offset / self::CHUNK_SIZE) * self::CHUNK_SIZE);
                $this->logger->info('Offset not aligned to chunk boundary, aligning down', [
                    'original_offset' => $offset,
                    'aligned_offset' => $alignedOffset,
                    'file_size' => $fileSize,
                    'note' => 'Server will ignore duplicate bytes via Range header',
                ]);
                $offset = $alignedOffset;
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

                // Calculate chunk size
                $remainingBytes = $fileSize - $offset;

                // Check if this is the last chunk
                $isLastChunk = $remainingBytes <= self::CHUNK_SIZE;

                if ($isLastChunk) {
                    // This is the last chunk - send all remaining bytes
                    $chunkSize = $remainingBytes;

                    if ($chunkSize > 0) {
                        // Read and send last chunk - ensure we read exactly chunkSize bytes
                        \fseek($handle, $offset);
                        $chunk = '';
                        $bytesRead = 0;
                        while ($bytesRead < $chunkSize) {
                            $bytesToRead = \max(1, $chunkSize - $bytesRead);
                            $data = \fread($handle, $bytesToRead);
                            if (false === $data || '' === $data) {
                                if (0 === $bytesRead) {
                                    throw new \RuntimeException(\sprintf('Failed to read last chunk at offset %d', $offset));
                                }
                                break; // EOF reached
                            }
                            $chunk .= $data;
                            $bytesRead += \strlen($data);
                        }

                        $actualChunkSize = \strlen($chunk);
                        $this->logger->debug('Last chunk read', [
                            'offset' => $offset,
                            'expected_size' => $chunkSize,
                            'actual_size' => $actualChunkSize,
                            'file_size' => $fileSize,
                        ]);

                        // Upload last chunk
                        $this->apiClient->uploadChunk(
                            sessionUri: $session->getSessionUri(),
                            chunk: $chunk,
                            offset: $offset,
                            totalSize: $fileSize
                        );
                        $offset += $actualChunkSize;
                        $job = $job->updateProgress($offset);
                        $this->jobRepository->save($job);
                    }
                    // Break loop - last chunk sent (or was 0), now complete upload
                    break;
                } else {
                    // Not the last chunk - must be exactly CHUNK_SIZE
                    $chunkSize = self::CHUNK_SIZE;

                    // Read chunk from file - ensure we read exactly chunkSize bytes
                    \fseek($handle, $offset);
                    $chunk = '';
                    $bytesRead = 0;
                    while ($bytesRead < $chunkSize) {
                        $bytesToRead = \max(1, $chunkSize - $bytesRead);
                        $data = \fread($handle, $bytesToRead);
                        if (false === $data || '' === $data) {
                            if (0 === $bytesRead) {
                                throw new \RuntimeException(\sprintf('Failed to read chunk at offset %d', $offset));
                            }
                            // EOF reached before reading full chunk - this should not happen for non-last chunks
                            throw new \RuntimeException(\sprintf('Unexpected EOF: expected %d bytes, got %d at offset %d (file size: %d)', $chunkSize, $bytesRead, $offset, $fileSize));
                        }
                        $chunk .= $data;
                        $bytesRead += \strlen($data);
                    }

                    $actualChunkSize = \strlen($chunk);

                    // CRITICAL: For non-last chunks, we MUST send exactly CHUNK_SIZE bytes
                    if ($actualChunkSize !== $chunkSize) {
                        throw new \RuntimeException(\sprintf('Failed to read full chunk: expected %d bytes, got %d at offset %d (file size: %d)', $chunkSize, $actualChunkSize, $offset, $fileSize));
                    }

                    $this->logger->debug('Chunk read for resume', [
                        'offset' => $offset,
                        'chunk_size' => $actualChunkSize,
                        'file_size' => $fileSize,
                    ]);

                    // Upload chunk - server will ignore bytes already uploaded
                    try {
                        $this->apiClient->uploadChunk(
                            sessionUri: $session->getSessionUri(),
                            chunk: $chunk,
                            offset: $offset,
                            totalSize: $fileSize
                        );
                    } catch (OffsetMismatchException $e) {
                        // Server expects different offset - query status and update
                        $this->logger->warning('Offset mismatch detected, querying server status', [
                            'current_offset' => $offset,
                            'expected_offset' => $e->getExpectedOffset(),
                        ]);

                        try {
                            $uploadStatus = $this->apiClient->queryUploadStatus($session->getSessionUri());
                            $offset = $uploadStatus->getUploadedBytes();
                            $this->logger->info('Updated offset from server', [
                                'new_offset' => $offset,
                            ]);
                            $job = $job->updateProgress($offset);
                            $this->jobRepository->save($job);
                            // Continue loop to retry with correct offset
                            continue;
                        } catch (\Exception) {
                            // If query fails, use expected offset from exception
                            $offset = $e->getExpectedOffset();
                            $job = $job->updateProgress($offset);
                            $this->jobRepository->save($job);
                            continue;
                        }
                    }

                    $this->quotaManager->recordBytesUploaded(\strlen($chunk));

                    $offset += \strlen($chunk);
                    $job = $job->updateProgress($offset);
                    $this->jobRepository->save($job);
                }
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

    /**
     * Extract quota reset time from HTTP response.
     */
    private function extractQuotaResetTime(\Psr\Http\Message\ResponseInterface $response): \DateTimeImmutable
    {
        $retryAfter = $response->getHeaderLine('Retry-After');
        if ('' !== $retryAfter && \is_numeric($retryAfter)) {
            return new \DateTimeImmutable()->add(new \DateInterval(\sprintf('PT%sS', $retryAfter)));
        }

        return new \DateTimeImmutable()->add(new \DateInterval('PT60S')); // Default 60 seconds
    }
}
