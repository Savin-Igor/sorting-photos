<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

interface GooglePhotosApiClientPort
{
    /**
     * Инициализировать resumable upload сессию.
     *
     * @return string Resumable session URI
     */
    public function initiateResumableUpload(int $fileSize, string $mimeType): string;

    /**
     * Загрузить чанк файла.
     */
    public function uploadChunk(string $sessionUri, string $chunk, int $offset, int $totalSize): void;

    /**
     * Завершить загрузку и получить upload token.
     */
    public function completeUpload(string $sessionUri): string;

    /**
     * Запросить статус загрузки (для проверки истекших сессий).
     */
    public function queryUploadStatus(string $sessionUri): UploadStatus;

    /**
     * Создать медиа-элементы батчем.
     *
     * @param array<BatchItemRequest> $items
     */
    public function batchCreateMediaItems(array $items, ?string $albumId = null): BatchCreateResponse;

    /**
     * Получить список медиа-элементов.
     *
     * @param int     $pageSize  Максимальное количество элементов (по умолчанию 25)
     * @param ?string $pageToken Токен для пагинации
     *
     * @return array{mediaItems: array<MediaItem>, nextPageToken: ?string}
     */
    public function listMediaItems(int $pageSize = 25, ?string $pageToken = null): array;

    /**
     * Получить список альбомов.
     *
     * @param int     $pageSize  Максимальное количество альбомов (по умолчанию 50)
     * @param ?string $pageToken Токен для пагинации
     *
     * @return array{albums: array<array{id: string, title: string, productUrl: string}>, nextPageToken: ?string}
     */
    public function listAlbums(int $pageSize = 50, ?string $pageToken = null): array;
}
