<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\Attribute;

use Attribute;
use SortingPhotosByDate\Domain\Storage\StorageType;

/**
 * Attribute to mark storage adapters.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class StorageAdapter
{
    public function __construct(
        public StorageType $type,
        public ?string $name = null,
    ) {
    }
}
