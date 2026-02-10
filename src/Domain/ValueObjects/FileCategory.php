<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\ValueObjects;

enum FileCategory: string
{
    case IMAGES = 'images';
    case AUDIO = 'audio';
    case VIDEO = 'video';
    case OTHER = 'other';
}
