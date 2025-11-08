<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos\Exception;

final class OffsetMismatchException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $expectedOffset,
    ) {
        parent::__construct($message);
    }

    public function getExpectedOffset(): int
    {
        return $this->expectedOffset;
    }
}
