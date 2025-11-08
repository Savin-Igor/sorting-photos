<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\QuotaExceededException;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\SessionExpiredException;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\BatchCreateResponse;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\BatchItemRequest;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\Error;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\GooglePhotosApiClientPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\MediaItem;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\NewMediaItemResult;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\Status;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadStatus;

final readonly class GooglePhotosApiClient implements GooglePhotosApiClientPort
{
    private const string BASE_URL = 'https://photoslibrary.googleapis.com/v1';

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private TokenManager $tokenManager,
    ) {
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
        $url = self::BASE_URL.'/uploads';
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', 'Bearer '.$this->getAccessToken())
            ->withHeader('X-Goog-Upload-Command', 'start')
            ->withHeader('X-Goog-Upload-Offset', '0')
            ->withHeader('X-Goog-Upload-Protocol', 'resumable')
            ->withHeader('X-Goog-Upload-Header', "Content-Type: {$mimeType}")
            ->withHeader('X-Goog-Upload-Size', (string) $fileSize)
            ->withHeader('Content-Length', '0');

        $response = $this->httpClient->sendRequest($request);

        // Handle quota errors
        if (429 === $response->getStatusCode()) {
            $resetTime = $this->extractQuotaResetTime($response);
            throw QuotaExceededException::requestsExceeded($resetTime);
        }

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(\sprintf('Failed to initiate resumable upload: %s %s', $response->getStatusCode(), $response->getBody()->getContents()));
        }

        $sessionUri = $response->getHeaderLine('X-Goog-Upload-URL');
        if ('' === $sessionUri) {
            throw new \RuntimeException('No X-Goog-Upload-URL header in response');
        }

        return $sessionUri;
    }

    public function uploadChunk(string $sessionUri, string $chunk, int $offset, int $totalSize): void
    {
        $chunkLength = \strlen($chunk);
        $endOffset = $offset + $chunkLength - 1;

        $request = $this->requestFactory->createRequest('PUT', $sessionUri)
            ->withHeader('Content-Range', "bytes {$offset}-{$endOffset}/{$totalSize}")
            ->withHeader('X-Goog-Upload-Offset', (string) $offset)
            ->withHeader('Content-Length', (string) $chunkLength)
            ->withBody($this->streamFactory->createStream($chunk));

        $response = $this->httpClient->sendRequest($request);

        // Handle quota errors
        if (429 === $response->getStatusCode()) {
            $resetTime = $this->extractQuotaResetTime($response);
            throw QuotaExceededException::requestsExceeded($resetTime);
        }

        if (!\in_array($response->getStatusCode(), [200, 308], true)) {
            throw new \RuntimeException(\sprintf('Failed to upload chunk: %s %s', $response->getStatusCode(), $response->getBody()->getContents()));
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
            $data = \json_decode($body, true);

            if (false === $data || !isset($data['uploadToken'])) {
                throw new \RuntimeException('No uploadToken in response');
            }

            return $data['uploadToken'];
        }

        throw new \RuntimeException(\sprintf('Upload not complete: %s %s', $response->getStatusCode(), $response->getBody()->getContents()));
    }

    public function queryUploadStatus(string $sessionUri): UploadStatus
    {
        // Use GET with X-Goog-Upload-Command: query to check status
        $request = $this->requestFactory->createRequest('GET', $sessionUri)
            ->withHeader('X-Goog-Upload-Command', 'query');

        $response = $this->httpClient->sendRequest($request);

        if (200 === $response->getStatusCode()) {
            // Upload completed
            $body = $response->getBody()->getContents();
            $data = \json_decode($body, true);

            if (false === $data || !isset($data['uploadToken'])) {
                throw new \RuntimeException('No uploadToken in response');
            }

            return UploadStatus::complete($data['uploadToken']);
        }

        if (308 === $response->getStatusCode()) {
            // Not completed, get uploaded bytes from Range header
            $range = $response->getHeaderLine('Range');
            if ('' === $range) {
                throw new \RuntimeException('No Range header in 308 response');
            }

            $uploadedBytes = $this->parseRange($range);

            return UploadStatus::incomplete($uploadedBytes);
        }

        // 404/410 - session expired
        if (\in_array($response->getStatusCode(), [404, 410], true)) {
            throw new SessionExpiredException('Resumable session expired');
        }

        throw new \RuntimeException(\sprintf('Unexpected status code: %s %s', $response->getStatusCode(), $response->getBody()->getContents()));
    }

    public function batchCreateMediaItems(array $items, ?string $albumId = null): BatchCreateResponse
    {
        $url = self::BASE_URL.'/mediaItems:batchCreate';

        $newMediaItems = \array_map(
            fn (BatchItemRequest $item): array => [
                'description' => $item->getFilename(),
                'simpleMediaItem' => [
                    'uploadToken' => $item->getUploadToken(),
                    // Use RFC 3339 format with timezone
                    'creationTime' => $item->getCreationTime()->format('Y-m-d\TH:i:s\Z'),
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

        $response = $this->httpClient->sendRequest($request);

        // Handle quota errors
        if (429 === $response->getStatusCode()) {
            $resetTime = $this->extractQuotaResetTime($response);
            throw QuotaExceededException::requestsExceeded($resetTime);
        }

        if (200 !== $response->getStatusCode()) {
            $errorBody = $response->getBody()->getContents();
            throw new \RuntimeException(\sprintf('Failed to batch create media items: %s %s', $response->getStatusCode(), $errorBody));
        }

        $body = $response->getBody()->getContents();
        $data = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        if (false === $data) {
            throw new \RuntimeException('Invalid JSON response from API');
        }

        $results = [];
        foreach ($data['newMediaItemResults'] ?? [] as $result) {
            $uploadToken = $result['uploadToken'] ?? '';
            $statusData = $result['status'] ?? [];
            $status = new Status(
                code: $statusData['code'] ?? 0,
                message: $statusData['message'] ?? ''
            );

            $mediaItem = null;
            if (isset($result['mediaItem'])) {
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
        }

        $errors = [];
        foreach ($data['errors'] ?? [] as $error) {
            $errors[] = new Error(
                code: $error['code'] ?? -1,
                message: $error['message'] ?? 'Unknown error',
                domain: $error['domain'] ?? null
            );
        }

        return new BatchCreateResponse($results, $errors);
    }

    public function listMediaItems(int $pageSize = 25, ?string $pageToken = null): array
    {
        $url = self::BASE_URL.'/mediaItems';

        $params = [
            'pageSize' => (string) $pageSize,
        ];

        if (null !== $pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $request = $this->requestFactory->createRequest('GET', $url.'?'.\http_build_query($params))
            ->withHeader('Authorization', 'Bearer '.$this->getAccessToken());

        $response = $this->httpClient->sendRequest($request);

        // Handle quota errors
        if (429 === $response->getStatusCode()) {
            $resetTime = $this->extractQuotaResetTime($response);
            throw QuotaExceededException::requestsExceeded($resetTime);
        }

        if (200 !== $response->getStatusCode()) {
            $errorBody = $response->getBody()->getContents();
            throw new \RuntimeException(\sprintf('Failed to list media items: %s %s', $response->getStatusCode(), $errorBody));
        }

        $data = \json_decode($response->getBody()->getContents(), true, 512, \JSON_THROW_ON_ERROR);

        if (false === $data) {
            throw new \RuntimeException('Invalid JSON response from API');
        }

        $mediaItems = [];
        foreach ($data['mediaItems'] ?? [] as $item) {
            $mediaItems[] = new MediaItem(
                id: $item['id'] ?? '',
                productUrl: $item['productUrl'] ?? ''
            );
        }

        return [
            'mediaItems' => $mediaItems,
            'nextPageToken' => $data['nextPageToken'] ?? null,
        ];
    }

    public function listAlbums(int $pageSize = 50, ?string $pageToken = null): array
    {
        $url = self::BASE_URL.'/albums';

        $params = [
            'pageSize' => (string) $pageSize,
        ];

        if (null !== $pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $request = $this->requestFactory->createRequest('GET', $url.'?'.\http_build_query($params))
            ->withHeader('Authorization', 'Bearer '.$this->getAccessToken());

        $response = $this->httpClient->sendRequest($request);

        // Handle quota errors
        if (429 === $response->getStatusCode()) {
            $resetTime = $this->extractQuotaResetTime($response);
            throw QuotaExceededException::requestsExceeded($resetTime);
        }

        if (200 !== $response->getStatusCode()) {
            $errorBody = $response->getBody()->getContents();
            throw new \RuntimeException(\sprintf('Failed to list albums: %s %s', $response->getStatusCode(), $errorBody));
        }

        $data = \json_decode($response->getBody()->getContents(), true, 512, \JSON_THROW_ON_ERROR);

        if (false === $data) {
            throw new \RuntimeException('Invalid JSON response from API');
        }

        $albums = [];
        foreach ($data['albums'] ?? [] as $album) {
            $albums[] = [
                'id' => $album['id'] ?? '',
                'title' => $album['title'] ?? '',
                'productUrl' => $album['productUrl'] ?? '',
            ];
        }

        return [
            'albums' => $albums,
            'nextPageToken' => $data['nextPageToken'] ?? null,
        ];
    }

    /**
     * Extract quota reset time from HTTP response.
     */
    private function extractQuotaResetTime(ResponseInterface $response): \DateTimeImmutable
    {
        // Try to extract Retry-After header
        $retryAfter = $response->getHeaderLine('Retry-After');
        if ('' !== $retryAfter && \is_numeric($retryAfter)) {
            return new \DateTimeImmutable(\sprintf('+%d seconds', (int) $retryAfter));
        }

        // If Retry-After is not present, use standard reset time (24 hours)
        return new \DateTimeImmutable('+1 day');
    }

    private function parseRange(string $range): int
    {
        // Range: bytes=0-12345
        if (\preg_match('/bytes=0-(\d+)/', $range, $matches)) {
            return (int) $matches[1] + 1; // +1 because range includes the last byte
        }

        return 0;
    }
}
