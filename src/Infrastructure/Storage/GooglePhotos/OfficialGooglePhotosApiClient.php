<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use Google\Auth\Credentials\UserRefreshCredentials;
use Google\Photos\Library\V1\PhotosLibraryClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\OffsetMismatchException;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\ServiceUnavailableException;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\BatchCreateResponse;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\BatchItemRequest;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\Error;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\GooglePhotosApiClientPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\MediaItem;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\NewMediaItemResult;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\Status;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadStatus;

/**
 * Adapter using official Google Photos Library PHP client library.
 * Uses library for batch operations and list operations.
 * Keeps custom resumable upload implementation for large files.
 */
final readonly class OfficialGooglePhotosApiClient implements GooglePhotosApiClientPort
{
    use QueryUploadStatusTrait;
    private const string BASE_URL = 'https://photoslibrary.googleapis.com/v1';

    private PhotosLibraryClient $libraryClient;

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private TokenManager $tokenManager,
        private CredentialsFactory $credentialsFactory,
        private TokenStorage $tokenStorage,
        private \SortingPhotosByDate\Ports\LoggerPort $logger,
    ) {
        // Create PhotosLibraryClient with our credentials
        $credentials = $this->createLibraryCredentials();
        $this->libraryClient = new PhotosLibraryClient([
            'credentials' => $credentials,
        ]);
    }

    /**
     * Create UserRefreshCredentials for PhotosLibraryClient.
     */
    private function createLibraryCredentials(): UserRefreshCredentials
    {
        $refreshToken = $this->tokenStorage->getRefreshToken();

        if (null === $refreshToken) {
            throw new \RuntimeException('No refresh token available. Please run: php bin/console google-photos:authorize');
        }

        return $this->credentialsFactory->createUserRefreshCredentials(
            $refreshToken,
            false // Use append-only scope for uploads
        );
    }

    /**
     * Get valid access token, refreshing it if necessary.
     */
    private function getAccessToken(): string
    {
        return $this->tokenManager->getValidAccessToken();
    }

    public function initiateResumableUpload(int $fileSize, string $mimeType): string
    {
        // Use resumable upload for all files (simpler and more reliable)
        $url = self::BASE_URL.'/uploads';
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', 'Bearer '.$this->getAccessToken())
            ->withHeader('X-Goog-Upload-Command', 'start')
            ->withHeader('X-Goog-Upload-Offset', '0')
            ->withHeader('X-Goog-Upload-Protocol', 'resumable')
            ->withHeader('X-Goog-Upload-Content-Type', $mimeType)
            ->withHeader('X-Goog-Upload-Size', (string) $fileSize)
            ->withHeader('Content-Length', '0');

        $this->logger->debug('Initiate resumable upload request', [
            'method' => 'POST',
            'url' => $url,
            'headers' => [
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Content-Type' => $mimeType,
                'X-Goog-Upload-Size' => (string) $fileSize,
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
            $this->logger->error('Failed to initiate resumable upload', [
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body_preview' => \substr($body, 0, 2048),
                'body_length' => \strlen($body),
            ]);
            throw new \RuntimeException(\sprintf('Failed to initiate resumable upload: %s %s', $response->getStatusCode(), $body));
        }

        $sessionUri = $response->getHeaderLine('X-Goog-Upload-URL');
        if ('' === $sessionUri) {
            throw new \RuntimeException('No X-Goog-Upload-URL header in response');
        }

        $this->logger->info('Resumable upload session created', [
            'status' => $response->getStatusCode(),
            'session_url_preview' => \substr($sessionUri, 0, 32).'...',
        ]);

        return $sessionUri;
    }

    public function uploadChunk(string $sessionUri, string $chunk, int $offset, int $totalSize): void
    {
        $chunkLength = \strlen($chunk);
        $endOffset = $offset + $chunkLength - 1;

        $request = $this->requestFactory->createRequest('PUT', $sessionUri)
            ->withHeader('X-Goog-Upload-Command', 'upload')
            ->withHeader('Content-Range', "bytes {$offset}-{$endOffset}/{$totalSize}")
            ->withHeader('X-Goog-Upload-Offset', (string) $offset)
            ->withHeader('Content-Length', (string) $chunkLength)
            ->withBody($this->streamFactory->createStream($chunk));

        $this->logger->debug('Uploading chunk', [
            'offset' => $offset,
            'end_offset' => $endOffset,
            'chunk_length' => $chunkLength,
            'total_size' => $totalSize,
            'session_url_preview' => \substr($sessionUri, 0, 32).'...',
        ]);

        $response = $this->httpClient->sendRequest($request);

        // Handle quota errors
        if (429 === $response->getStatusCode()) {
            $resetTime = $this->extractQuotaResetTime($response);
            throw QuotaExceededException::requestsExceeded($resetTime);
        }

        if (!\in_array($response->getStatusCode(), [200, 308], true)) {
            $body = $response->getBody()->getContents();

            // Check for offset mismatch error (400)
            if (400 === $response->getStatusCode() && \preg_match('/instead of (\d+)/', $body, $matches)) {
                $expectedOffset = (int) $matches[1];
                $this->logger->error('Chunk upload offset mismatch', [
                    'status' => $response->getStatusCode(),
                    'body_preview' => \substr($body, 0, 1024),
                    'expected_offset' => $expectedOffset,
                ]);
                throw new OffsetMismatchException(\sprintf('Failed to upload chunk: %s %s', $response->getStatusCode(), $body), $expectedOffset);
            }

            $this->logger->error('Chunk upload failed', [
                'status' => $response->getStatusCode(),
                'body_preview' => \substr($body, 0, 1024),
            ]);
            throw new \RuntimeException(\sprintf('Failed to upload chunk: %s %s', $response->getStatusCode(), $body));
        }

        // Check Range in 308 response to confirm accepted bytes
        if (308 === $response->getStatusCode()) {
            $range = $response->getHeaderLine('Range');
            if ('' === $range) {
                throw new \RuntimeException('No Range header in 308 response');
            }

            $confirmedBytes = $this->parseRange($range);
            // Verify that confirmed range matches expected
            if ($confirmedBytes < $offset + $chunkLength) {
                throw new \RuntimeException(\sprintf('Range mismatch: expected at least %d bytes, got %d', $offset + $chunkLength, $confirmedBytes));
            }
            $this->logger->debug('Chunk acknowledged (308)', [
                'range' => $range,
                'confirmed_bytes' => $confirmedBytes,
            ]);
        }
    }

    public function completeUpload(string $sessionUri): string
    {
        // For upload completion use PUT without Range header
        // or POST with X-Goog-Upload-Command: upload, finalize
        // Using PUT without Range signals completion
        $request = $this->requestFactory->createRequest('PUT', $sessionUri)
            ->withHeader('Content-Length', '0');

        $response = $this->httpClient->sendRequest($request);

        // Handle quota errors
        if (429 === $response->getStatusCode()) {
            $resetTime = $this->extractQuotaResetTime($response);
            throw QuotaExceededException::requestsExceeded($resetTime);
        }

        if (200 === $response->getStatusCode()) {
            $body = $response->getBody()->getContents();
            // Response body should contain upload token
            $token = \trim($body);
            if ('' === $token) {
                throw new \RuntimeException('Empty upload token in response');
            }

            $this->logger->info('Upload session finalized', [
                'status' => $response->getStatusCode(),
                'token_length' => \strlen($token),
                'token_preview' => \substr($token, 0, 16).'...',
            ]);

            return $token;
        }

        $this->logger->error('Upload not complete', [
            'status' => $response->getStatusCode(),
            'body_preview' => \substr($response->getBody()->getContents(), 0, 2048),
        ]);
        throw new \RuntimeException(\sprintf('Upload not complete: %s %s', $response->getStatusCode(), $response->getBody()->getContents()));
    }

    public function queryUploadStatus(string $sessionUri): UploadStatus
    {
        return $this->queryUploadStatusInternal(
            $this->httpClient,
            $this->requestFactory,
            $sessionUri,
            function (array $data): void {
                $this->logger->debug('Upload status: completed', [
                    'status' => 200,
                    'token_length' => isset($data['uploadToken']) ? \strlen((string) $data['uploadToken']) : 0,
                ]);
            },
            function (string $range, int $uploadedBytes): void {
                $this->logger->debug('Upload status: in-progress', [
                    'status' => 308,
                    'range' => $range,
                    'uploaded_bytes' => $uploadedBytes,
                ]);
            },
            function (int $statusCode, string $body): void {
                // Handle temporary server errors
                if (503 === $statusCode) {
                    $this->logger->warning('Google Photos service temporarily unavailable', [
                        'status' => 503,
                    ]);
                    throw new ServiceUnavailableException('Service temporarily unavailable');
                }

                // Handle session expired (404/410) - already handled in trait, but log here
                if (\in_array($statusCode, [404, 410], true)) {
                    $this->logger->warning('Upload session expired', [
                        'status' => $statusCode,
                    ]);

                    // Exception will be thrown by trait
                    return;
                }

                $this->logger->error('Failed to query upload status', [
                    'status' => $statusCode,
                    'body_preview' => \substr($body, 0, 1024),
                ]);
            }
        );
    }

    public function batchCreateMediaItems(array $items, ?string $albumId = null): BatchCreateResponse
    {
        // Use JSON API directly to have full control over fields
        // IMPORTANT: Google Photos API does NOT support creationTime field in batchCreateMediaItems.
        // The creation time is automatically extracted from EXIF metadata (DateTimeOriginal) in the uploaded file.
        // We set EXIF DateTimeOriginal before upload using ExifDateSetter service.
        // For videos, creation time is extracted from file metadata if available.
        $url = self::BASE_URL.'/mediaItems:batchCreate';

        $newMediaItems = \array_map(
            fn (BatchItemRequest $item): array => [
                'description' => $item->getFilename(),
                'simpleMediaItem' => [
                    'uploadToken' => $item->getUploadToken(),
                    // Set filename - priority for display in Google Photos
                    'fileName' => $item->getFilename(),
                    // Note: creationTime is NOT supported here - it's read from EXIF metadata
                ],
            ],
            $items
        );

        $body = [
            'newMediaItems' => $newMediaItems,
        ];

        if (null !== $albumId && '' !== $albumId) {
            $body['albumId'] = $albumId;
        }

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', 'Bearer '.$this->getAccessToken())
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(\json_encode($body, \JSON_THROW_ON_ERROR)));

        try {
            $this->logger->info('Creating media items (batch)', [
                'items' => \count($items),
                'album_id' => $body['albumId'] ?? null,
            ]);
            $response = $this->httpClient->sendRequest($request);

            // Handle quota errors
            if (429 === $response->getStatusCode()) {
                $resetTime = $this->extractQuotaResetTime($response);
                throw QuotaExceededException::requestsExceeded($resetTime);
            }

            if (200 !== $response->getStatusCode()) {
                $errorBody = $response->getBody()->getContents();
                $this->logger->error('Batch create media items failed', [
                    'status' => $response->getStatusCode(),
                    'body_preview' => \substr($errorBody, 0, 4096),
                    'body_length' => \strlen($errorBody),
                ]);
                throw new \RuntimeException(\sprintf('Failed to batch create media items: %s %s', $response->getStatusCode(), $errorBody));
            }

            $data = \json_decode($response->getBody()->getContents(), true, 512, \JSON_THROW_ON_ERROR);

            if (false === $data) {
                throw new \RuntimeException('Invalid JSON response from API');
            }

            // Optional: debug preview of Google response
            $this->logger->debug('Batch create response preview', [
                'has_newMediaItemResults' => isset($data['newMediaItemResults']),
                'first_result' => isset($data['newMediaItemResults'][0]) ? \array_intersect_key(
                    $data['newMediaItemResults'][0],
                    ['uploadToken' => true, 'status' => true, 'mediaItem' => true]
                ) : null,
            ]);

            // Convert API response to our format
            $results = [];
            $errors = [];

            foreach ($data['newMediaItemResults'] ?? [] as $result) {
                $uploadToken = $result['uploadToken'] ?? '';
                $statusData = $result['status'] ?? [];
                $status = new Status(
                    code: $statusData['code'] ?? 0,
                    message: $statusData['message'] ?? ''
                );

                $mediaItem = null;
                if ($status->isSuccess() && isset($result['mediaItem'])) {
                    $mediaItem = new MediaItem(
                        id: $result['mediaItem']['id'] ?? '',
                        productUrl: $result['mediaItem']['productUrl'] ?? ''
                    );
                }

                $results[] = new NewMediaItemResult(
                    uploadToken: $uploadToken,
                    status: $status,
                    mediaItem: $mediaItem
                );

                if (!$status->isSuccess()) {
                    $errors[] = new Error(
                        code: $status->getCode(),
                        message: $status->getMessage()
                    );
                }
            }

            // Process batch-level errors
            foreach ($data['errors'] ?? [] as $error) {
                $errors[] = new Error(
                    code: $error['code'] ?? -1,
                    message: $error['message'] ?? 'Unknown error',
                    domain: $error['domain'] ?? null
                );
            }

            // Summary log
            $successCount = 0;
            foreach ($results as $r) {
                if ($r->getStatus()->isSuccess()) {
                    ++$successCount;
                }
            }
            $this->logger->info('Batch create media items result', [
                'status' => 200,
                'total' => \count($results),
                'success' => $successCount,
                'failed' => \count($errors),
                'failed_items' => \array_map(
                    fn (Error $e): array => ['code' => $e->getCode(), 'message' => $e->getMessage(), 'domain' => $e->getDomain()],
                    $errors
                ),
            ]);

            return new BatchCreateResponse($results, $errors);
        } catch (\JsonException $e) {
            $this->logger->error('Failed to decode JSON response from Google Photos', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to decode JSON response: '.$e->getMessage(), 0, $e);
        }
    }

    public function listMediaItems(int $pageSize = 25, ?string $pageToken = null): array
    {
        try {
            $optionalArgs = [
                'pageSize' => $pageSize,
            ];
            if (null !== $pageToken) {
                $optionalArgs['pageToken'] = $pageToken;
            }

            $pagedResponse = $this->libraryClient->listMediaItems($optionalArgs);

            $mediaItems = [];
            $nextPageToken = null;

            // Iterate through first page only (as per our API contract)
            foreach ($pagedResponse->iteratePages() as $page) {
                foreach ($page as $libraryItem) {
                    $mediaItems[] = new MediaItem(
                        $libraryItem->getId(),
                        $libraryItem->getProductUrl()
                    );
                }
                // Get next page token from page
                $nextPageToken = $page->getNextPageToken();
                if ('' === $nextPageToken) {
                    $nextPageToken = null;
                }
                // Only process first page
                break;
            }

            return [
                'mediaItems' => $mediaItems,
                'nextPageToken' => $nextPageToken,
            ];
        } catch (\Google\ApiCore\ApiException $e) {
            if (429 === $e->getCode()) {
                $resetTime = new \DateTimeImmutable('+1 hour');
                throw QuotaExceededException::requestsExceeded($resetTime);
            }

            throw new \RuntimeException(\sprintf('Failed to list media items: %s', $e->getMessage()), $e->getCode(), $e);
        }
    }

    public function listAlbums(int $pageSize = 50, ?string $pageToken = null): array
    {
        try {
            $optionalArgs = [
                'pageSize' => $pageSize,
            ];
            if (null !== $pageToken) {
                $optionalArgs['pageToken'] = $pageToken;
            }

            $pagedResponse = $this->libraryClient->listAlbums($optionalArgs);

            $albums = [];
            $nextPageToken = null;

            // Iterate through first page only (as per our API contract)
            foreach ($pagedResponse->iteratePages() as $page) {
                foreach ($page as $libraryAlbum) {
                    $albums[] = [
                        'id' => $libraryAlbum->getId(),
                        'title' => $libraryAlbum->getTitle(),
                        'productUrl' => $libraryAlbum->getProductUrl(),
                    ];
                }
                // Get next page token from page
                $nextPageToken = $page->getNextPageToken();
                if ('' === $nextPageToken) {
                    $nextPageToken = null;
                }
                // Only process first page
                break;
            }

            return [
                'albums' => $albums,
                'nextPageToken' => $nextPageToken,
            ];
        } catch (\Google\ApiCore\ApiException $e) {
            if (429 === $e->getCode()) {
                $resetTime = new \DateTimeImmutable('+1 hour');
                throw QuotaExceededException::requestsExceeded($resetTime);
            }

            throw new \RuntimeException(\sprintf('Failed to list albums: %s', $e->getMessage()), $e->getCode(), $e);
        }
    }

    /**
     * Extract quota reset time from response headers.
     */
    private function extractQuotaResetTime(\Psr\Http\Message\ResponseInterface $response): \DateTimeImmutable
    {
        $resetHeader = $response->getHeaderLine('Retry-After');
        if ('' !== $resetHeader) {
            $seconds = (int) $resetHeader;
            if ($seconds > 0) {
                return new \DateTimeImmutable(\sprintf('+%d seconds', $seconds));
            }
        }

        // Default: reset in 1 hour
        return new \DateTimeImmutable('+1 hour');
    }

    /**
     * Parse Range header to get uploaded bytes.
     * Format: "bytes=0-123456" or "bytes 0-123456/1234567".
     */
    private function parseRange(string $range): int
    {
        // Handle "bytes=0-123456" format
        if (\preg_match('/bytes=\d+-(\d+)/', $range, $matches)) {
            return (int) $matches[1] + 1; // +1 because range is inclusive
        }

        // Handle "bytes 0-123456/1234567" format
        if (\preg_match('/bytes \d+-(\d+)\//', $range, $matches)) {
            return (int) $matches[1] + 1;
        }

        throw new \RuntimeException(\sprintf('Invalid Range header format: %s', $range));
    }
}
