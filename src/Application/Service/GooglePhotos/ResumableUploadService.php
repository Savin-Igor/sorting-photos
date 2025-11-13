<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\ExifDateSetter;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\ImageCompressor;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\TokenManager;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\VideoCompressor;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;

/**
 * Simplified upload service that uses raw upload for all files.
 * No chunking, no resumable uploads - just simple and reliable.
 */
final readonly class ResumableUploadService
{
    public function __construct(
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
        $this->logger->debug('Starting file upload', [
            'job_id' => $job->getId(),
            'file_path' => $job->getFilePath()->getPath(),
            'file_size' => $job->getFileSize(),
            'mime_type' => $job->getMimeType(),
            'state' => $job->getState()->value,
        ]);

        // 0. Check if source file exists before any processing
        $sourceFilePath = $job->getFilePath()->getPath();
        if (!\file_exists($sourceFilePath)) {
            $this->logger->warning('Source file not found, marking as NOT_FOUND', [
                'job_id' => $job->getId()->getId(),
                'file_path' => $sourceFilePath,
            ]);
            $job = $job->markAsNotFound();
            $this->jobRepository->save($job);
            return; // Exit early - file doesn't exist
        }

        // 1. Check quota
        try {
            $this->quotaManager->checkQuota();
        } catch (\Exception $e) {
            $this->logger->error('Quota check failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        // 2. If there's an existing resumable session, expire it (we use raw upload for everything)
        if ($job->getResumableSession() instanceof \SortingPhotosByDate\Domain\Storage\GooglePhotos\ResumableSession) {
            $this->logger->info('Expiring existing resumable session, using raw upload instead');
            $lastKnownBytes = $job->getResumableSession()->getUploadedBytes();
            $job = $job->markSessionExpired($lastKnownBytes);
            $this->jobRepository->save($job);
        }

        // 3. Compress file if needed
        $filePath = $this->compressIfNeeded($job);

        // 4. Check if file exists before processing
        if (!\file_exists($filePath)) {
            $this->logger->warning('File not found, marking as NOT_FOUND', [
                'job_id' => $job->getId()->getId(),
                'file_path' => $filePath,
            ]);
            $job = $job->markAsNotFound();
            $this->jobRepository->save($job);
            return; // Exit early - file doesn't exist
        }

        // 5. Set EXIF creation time if missing (Google Photos reads this from EXIF)
        $this->exifDateSetter->setCreationTime($filePath, $job->getCreationTime());

        $fileSize = \filesize($filePath);
        if (false === $fileSize) {
            throw new \RuntimeException(\sprintf('Failed to get file size: %s', $filePath));
        }

        $this->logger->info('Uploading to Google Photos (raw)', [
            'file_path' => $filePath,
            'file_name' => \basename($filePath),
            'file_size' => $fileSize,
            'file_size_kb' => \round($fileSize / 1024, 2),
            'mime_type' => $job->getMimeType(),
        ]);

        // 5. Read entire file
        $fileContent = \file_get_contents($filePath);
        if (false === $fileContent) {
            throw new \RuntimeException(\sprintf('Failed to read file: %s', $filePath));
        }

        // 6. Raw upload - POST entire file in one request
        $url = 'https://photoslibrary.googleapis.com/v1/uploads';
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', 'Bearer '.$this->tokenManager->getValidAccessToken())
            ->withHeader('X-Goog-Upload-Protocol', 'raw')
            ->withHeader('X-Goog-Upload-Content-Type', $job->getMimeType())
            ->withHeader('Content-Type', $job->getMimeType())
            ->withHeader('Content-Length', (string) $fileSize)
            ->withBody($this->streamFactory->createStream($fileContent));

        // Log request metadata (without Authorization header)
        $this->logger->debug('Google Photos upload request', [
            'method' => 'POST',
            'url' => $url,
            'headers' => [
                // Safe subset, without Authorization
                'X-Goog-Upload-Protocol' => 'raw',
                'X-Goog-Upload-Content-Type' => $job->getMimeType(),
                'Content-Type' => $job->getMimeType(),
                'Content-Length' => (string) $fileSize,
            ],
        ]);

        $response = $this->httpClient->sendRequest($request);

        // Handle quota errors
        if (429 === $response->getStatusCode()) {
            $resetTime = $this->extractQuotaResetTime($response);
            throw QuotaExceededException::requestsExceeded($resetTime);
        }

        if (200 !== $response->getStatusCode()) {
            $body = $response->getBody()->getContents();
            $this->logger->error('Google Photos raw upload failed', [
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body_preview' => \substr($body, 0, 2048),
                'body_length' => \strlen($body),
            ]);
            throw new \RuntimeException(\sprintf('Failed to upload file: %s %s', $response->getStatusCode(), $body));
        }

        $uploadToken = $response->getBody()->getContents();
        $this->logger->info('Google Photos raw upload response (token received)', [
            'status' => $response->getStatusCode(),
            'headers' => [
                // Log a safe subset of headers
                'X-Goog-Upload-Status' => $response->getHeaderLine('X-Goog-Upload-Status'),
            ],
            'token_length' => \strlen($uploadToken),
            'token_preview' => \substr($uploadToken, 0, 16).'...',
        ]);

        // 7. Update job state - mark as uploaded
        // Create a virtual session URI for state transition
        $virtualSessionUri = 'raw://'.$job->getId()->getId();
        $job = $job->startUpload($virtualSessionUri);
        $this->jobRepository->save($job);

        // Complete the upload
        $job = $job->completeUpload($uploadToken);
        $this->jobRepository->save($job);

        $this->logger->info('File upload completed', [
            'job_id' => $job->getId()->getId(),
            'file_path' => $filePath,
            'file_size' => $fileSize,
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
