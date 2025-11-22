<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Carbon\Carbon;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Domain\Storage\GooglePhotos\UploadJob;
use SortingPhotosByDate\Exceptions\FileOperationException;
use SortingPhotosByDate\Exceptions\GooglePhotosApiException;
use SortingPhotosByDate\Infrastructure\Metadata\FilenameDateExtractor;
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
        private FilenameDateExtractor $filenameDateExtractor,
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

        // 3. Compress file if needed (pass creation time to preserve metadata)
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

        // 5. Set EXIF/creation time metadata if missing (Google Photos reads this from metadata)
        // For videos: prefer date from filename if available (more reliable than metadata)
        // For images: EXIF date is set here
        $creationTime = $job->getCreationTime();

        // For videos, check if filename contains a more accurate date
        if ($job->isVideo()) {
            $filenameDate = $this->filenameDateExtractor->extract($sourceFilePath);
            if ($filenameDate instanceof Carbon) {
                // Use filename date if it has time component (more precise)
                // or if it's different from current creation time (filename is more reliable)
                $filenameHasTime = !(0 === $filenameDate->hour && 0 === $filenameDate->minute && 0 === $filenameDate->second);
                $currentTimeCarbon = Carbon::instance($creationTime);

                // Use filename date if:
                // 1. Filename has time component (more precise)
                // 2. Or dates differ significantly (filename is more reliable source)
                $shouldUseFilenameDate = false;
                if ($filenameHasTime) {
                    // Filename has time - use it (more precise)
                    $shouldUseFilenameDate = true;
                } else {
                    // Compare dates - if they differ, prefer filename date
                    $dateDiff = abs($currentTimeCarbon->diffInDays($filenameDate));
                    if ($dateDiff > 0) {
                        $shouldUseFilenameDate = true;
                    }
                }

                if ($shouldUseFilenameDate) {
                    $creationTime = \DateTimeImmutable::createFromMutable($filenameDate->toDateTime());
                    $this->logger->info('Using date from filename for video', [
                        'file_path' => $sourceFilePath,
                        'original_date' => $job->getCreationTime()->format('Y-m-d H:i:s'),
                        'filename_date' => $filenameDate->format('Y-m-d H:i:s'),
                        'has_time' => $filenameHasTime,
                    ]);
                }
            }
        }

        $this->exifDateSetter->setCreationTime($filePath, $creationTime);

        $fileSize = \filesize($filePath);
        if (false === $fileSize) {
            throw FileOperationException::failedGetSize($filePath);
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
            throw FileOperationException::failedRead($filePath);
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
            throw GooglePhotosApiException::failedUploadChunk($response->getStatusCode(), $body);
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
            // For videos, prefer date from filename if available (more reliable)
            $creationTime = $job->getCreationTime();
            $filenameDate = $this->filenameDateExtractor->extract($filePath);
            if ($filenameDate instanceof Carbon) {
                $filenameHasTime = !(0 === $filenameDate->hour && 0 === $filenameDate->minute && 0 === $filenameDate->second);
                $currentTimeCarbon = Carbon::instance($creationTime);

                // Use filename date if it has time or differs from current date
                if ($filenameHasTime || abs($currentTimeCarbon->diffInDays($filenameDate)) > 0) {
                    $creationTime = \DateTimeImmutable::createFromMutable($filenameDate->toDateTime());
                }
            }

            // Pass creation time to VideoCompressor to preserve metadata during compression
            return $this->videoCompressor->compressIfNeeded($filePath, $creationTime);
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
