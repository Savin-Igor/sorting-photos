<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
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
        private string $accessToken,
    ) {
    }

    public function initiateResumableUpload(int $fileSize, string $mimeType): string
    {
        $url = self::BASE_URL.'/uploads';
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', 'Bearer '.$this->accessToken)
            ->withHeader('X-Goog-Upload-Command', 'start')
            ->withHeader('X-Goog-Upload-Offset', '0')
            ->withHeader('X-Goog-Upload-Protocol', 'resumable')
            ->withHeader('X-Goog-Upload-Header', "Content-Type: {$mimeType}")
            ->withHeader('Content-Length', (string) $fileSize);

        $response = $this->httpClient->sendRequest($request);

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

        if (!\in_array($response->getStatusCode(), [200, 308], true)) {
            throw new \RuntimeException(\sprintf('Failed to upload chunk: %s %s', $response->getStatusCode(), $response->getBody()->getContents()));
        }
    }

    public function completeUpload(string $sessionUri): string
    {
        // Для завершения загрузки нужно сделать GET запрос без Range header
        $request = $this->requestFactory->createRequest('GET', $sessionUri);

        $response = $this->httpClient->sendRequest($request);

        if (200 === $response->getStatusCode()) {
            $body = $response->getBody()->getContents();
            $data = \json_decode((string) $body, true);

            if (false === $data || !isset($data['uploadToken'])) {
                throw new \RuntimeException('No uploadToken in response');
            }

            return $data['uploadToken'];
        }

        throw new \RuntimeException(\sprintf('Upload not complete: %s %s', $response->getStatusCode(), $response->getBody()->getContents()));
    }

    public function queryUploadStatus(string $sessionUri): UploadStatus
    {
        // Используем GET с Range header для проверки статуса
        $request = $this->requestFactory->createRequest('GET', $sessionUri)
            ->withHeader('Range', 'bytes=0-0');

        $response = $this->httpClient->sendRequest($request);

        if (200 === $response->getStatusCode()) {
            // Загрузка завершена
            $body = $response->getBody()->getContents();
            $data = \json_decode((string) $body, true);

            if (false === $data || !isset($data['uploadToken'])) {
                throw new \RuntimeException('No uploadToken in response');
            }

            return UploadStatus::complete($data['uploadToken']);
        }

        if (308 === $response->getStatusCode()) {
            // Не завершено, получить uploaded bytes из Range header
            $range = $response->getHeaderLine('Range');
            $uploadedBytes = $this->parseRange($range);

            return UploadStatus::incomplete($uploadedBytes);
        }

        // 404/410 - сессия истекла
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
                    'creationTime' => $item->getCreationTime()->format('Y-m-d\TH:i:s\Z'),
                ],
            ],
            $items
        );

        $body = [
            'newMediaItems' => $newMediaItems,
        ];

        if (null !== $albumId) {
            $body['albumId'] = $albumId;
        }

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', 'Bearer '.$this->accessToken)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(\json_encode($body, \JSON_THROW_ON_ERROR)));

        $response = $this->httpClient->sendRequest($request);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(\sprintf('Failed to batch create media items: %s %s', $response->getStatusCode(), $response->getBody()->getContents()));
        }

        $body = $response->getBody()->getContents();
        $data = \json_decode((string) $body, true, 512, \JSON_THROW_ON_ERROR);

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

    private function parseRange(string $range): int
    {
        // Range: bytes=0-12345
        if (\preg_match('/bytes=0-(\d+)/', $range, $matches)) {
            return (int) $matches[1] + 1; // +1 потому что range включает последний байт
        }

        return 0;
    }
}
