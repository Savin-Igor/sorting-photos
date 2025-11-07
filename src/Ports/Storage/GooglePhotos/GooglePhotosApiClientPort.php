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
}
