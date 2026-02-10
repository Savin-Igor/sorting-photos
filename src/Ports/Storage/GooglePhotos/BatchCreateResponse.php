<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

final readonly class BatchCreateResponse
{
    /**
     * @param array<NewMediaItemResult> $newMediaItemResults
     * @param array<Error>              $errors
     */
    public function __construct(
        private array $newMediaItemResults,
        private array $errors,
    ) {
    }

    /**
     * @return array<NewMediaItemResult>
     */
    public function getNewMediaItemResults(): array
    {
        return $this->newMediaItemResults;
    }

    /**
     * @return array<Error>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
