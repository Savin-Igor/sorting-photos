<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\SessionExpiredException;
use SortingPhotosByDate\Domain\Event\UploadInitiated;
use SortingPhotosByDate\Domain\Event\UploadProgressed;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\ImageCompressor;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\VideoCompressor;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\GooglePhotosApiClientPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;
use Symfony\Component\Messenger\MessageBusInterface;

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
        private MessageBusInterface $messageBus,
        private LoggerPort $logger,
    ) {
    }

    public function uploadFile(UploadJob $job): void
    {
        // 1. Check quota
        $this->quotaManager->checkQuota();

        // 2. Check session expiration
        $now = new \DateTimeImmutable();
        if ($job->needsSessionRenewal($now)) {
            $this->handleExpiredSession($job);
            $job = $this->jobRepository->findById($job->getId());
            if (!$job instanceof UploadJob) {
                throw new \RuntimeException('Job not found after session expiration handling');
            }
        }

        // 3. If session exists - resume
        if ($job->getResumableSession() instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\ResumableSession) {
            $this->resumeUpload($job);

            return;
        }

        // 4. Compress file if needed
        $filePath = $this->compressIfNeeded($job);
        $fileSize = \filesize($filePath);
        if (false === $fileSize) {
            throw new \RuntimeException(\sprintf('Failed to get file size: %s', $filePath));
        }

        // 5. Initialize new session
        $sessionUri = $this->apiClient->initiateResumableUpload(
            fileSize: $fileSize,
            mimeType: $job->getMimeType()
        );

        $job = $job->startUpload($sessionUri);
        $this->jobRepository->save($job);

        $this->messageBus->dispatch(new UploadInitiated(
            jobId: $job->getId(),
            filePath: $job->getFilePath()->getPath()
        ));

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

                // Read chunk
                \fseek($handle, $offset);
                $chunk = \fread($handle, self::CHUNK_SIZE);
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

                // Dispatch progress event
                $this->messageBus->dispatch(new UploadProgressed(
                    jobId: $job->getId(),
                    uploadedBytes: $offset,
                    totalBytes: $fileSize
                ));

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
            while ($offset < $fileSize) {
                // Check quota
                try {
                    $this->quotaManager->checkQuota();
                } catch (QuotaExceededException $e) {
                    $job = $job->pauseByQuota();
                    $this->jobRepository->save($job);
                    throw $e;
                }

                // Read chunk
                \fseek($handle, $offset);
                $chunk = \fread($handle, self::CHUNK_SIZE);
                if (false === $chunk) {
                    throw new \RuntimeException(\sprintf('Failed to read chunk at offset %d', $offset));
                }

                // Upload chunk
                $this->apiClient->uploadChunk(
                    sessionUri: $session->getSessionUri(),
                    chunk: $chunk,
                    offset: $offset,
                    totalSize: $fileSize
                );

                $this->quotaManager->recordBytesUploaded(\strlen($chunk));

                $offset += \strlen($chunk);
                $job = $job->updateProgress($offset);
                $this->jobRepository->save($job);

                $this->messageBus->dispatch(new UploadProgressed(
                    jobId: $job->getId(),
                    uploadedBytes: $offset,
                    totalBytes: $fileSize
                ));
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
