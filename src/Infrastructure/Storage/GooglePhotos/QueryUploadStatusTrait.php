<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\SessionExpiredException;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadStatus;

/**
 * Trait for querying upload status.
 * Extracts common logic for queryUploadStatus method.
 */
trait QueryUploadStatusTrait
{
    /**
     * Query upload status using resumable session URI.
     * Common implementation for both API clients.
     *
     * @param callable(array<string, mixed>): void|null $onCompleted  Callback when upload completed
     * @param callable(string, int): void|null          $onIncomplete Callback when upload incomplete
     * @param callable(int, string): void|null          $onError      Callback on error
     */
    private function queryUploadStatusInternal(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        string $sessionUri,
        ?callable $onCompleted = null,
        ?callable $onIncomplete = null,
        ?callable $onError = null,
    ): UploadStatus {
        // Use PUT with X-Goog-Upload-Command: query to check status
        $request = $requestFactory->createRequest('PUT', $sessionUri)
            ->withHeader('X-Goog-Upload-Command', 'query')
            ->withHeader('Content-Length', '0');

        $response = $httpClient->sendRequest($request);

        if (200 === $response->getStatusCode()) {
            // Upload completed
            $body = $response->getBody()->getContents();
            /** @var array<string, mixed>|null $data */
            $data = \json_decode($body, true);

            if (!\is_array($data) || !isset($data['uploadToken'])) {
                throw new \RuntimeException('No uploadToken in response');
            }

            if (null !== $onCompleted) {
                $onCompleted($data);
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

            if (null !== $onIncomplete) {
                $onIncomplete($range, $uploadedBytes);
            }

            return UploadStatus::incomplete($uploadedBytes);
        }

        // 404/410 - session expired
        if (\in_array($response->getStatusCode(), [404, 410], true)) {
            if (null !== $onError) {
                $onError($response->getStatusCode(), '');
            }
            throw new SessionExpiredException('Resumable session expired');
        }

        // Handle other status codes (503, etc.) - let caller handle via onError callback
        $body = $response->getBody()->getContents();
        if (null !== $onError) {
            $onError($response->getStatusCode(), $body);
        }
        throw new \RuntimeException(\sprintf('Unexpected status code: %s %s', $response->getStatusCode(), $body));
    }

    /**
     * Parse Range header to get uploaded bytes.
     */
    abstract protected function parseRange(string $range): int;
}
