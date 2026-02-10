<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

interface GooglePhotosApiClientPort
{
    /**
     * Initialize resumable upload session.
     *
     * @return string Resumable session URI
     */
    public function initiateResumableUpload(int $fileSize, string $mimeType): string;

    /**
     * Upload file chunk.
     */
    public function uploadChunk(string $sessionUri, string $chunk, int $offset, int $totalSize): void;

    /**
     * Complete upload and get upload token.
     */
    public function completeUpload(string $sessionUri): string;

    /**
     * Query upload status (for checking expired sessions).
     */
    public function queryUploadStatus(string $sessionUri): UploadStatus;

    /**
     * Create media items in batch.
     *
     * @param array<BatchItemRequest> $items
     */
    public function batchCreateMediaItems(array $items, ?string $albumId = null): BatchCreateResponse;

    /**
     * Get list of media items.
     *
     * @param int     $pageSize  Maximum number of items (default 25)
     * @param ?string $pageToken Pagination token
     *
     * @return array{mediaItems: array<MediaItem>, nextPageToken: ?string}
     */
    public function listMediaItems(int $pageSize = 25, ?string $pageToken = null): array;

    /**
     * Get list of albums.
     *
     * @param int     $pageSize  Maximum number of albums (default 50)
     * @param ?string $pageToken Pagination token
     *
     * @return array{albums: array<array{id: string, title: string, productUrl: string}>, nextPageToken: ?string}
     */
    public function listAlbums(int $pageSize = 50, ?string $pageToken = null): array;
}
