<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Filter\Filter;

use SortingPhotosByDate\Application\Filter\FileFilterInterface;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Filter by file type (image/video).
 */
final readonly class FileTypeFilter implements FileFilterInterface
{
    /**
     * @param array<string>|null $allowedTypes Allowed types ['image', 'video'] or null = all
     * @param array<string>|null $deniedTypes  Denied types ['image'] or null = no denials
     */
    public function __construct(
        private ?array $allowedTypes = null,
        private ?array $deniedTypes = null,
    ) {
    }

    #[\Override]
    public function shouldSkip(FilePath $filePath, array $context = []): bool
    {
        // Determine file type from context
        $fileType = null;
        if (isset($context['is_video']) && \is_bool($context['is_video'])) {
            $fileType = $context['is_video'] ? 'video' : 'image';
        } elseif (isset($context['mime_type']) && \is_string($context['mime_type'])) {
            $mimeType = $context['mime_type'];
            if (\str_starts_with($mimeType, 'image/')) {
                $fileType = 'image';
            } elseif (\str_starts_with($mimeType, 'video/')) {
                $fileType = 'video';
            }
        }

        if (null === $fileType) {
            return false; // Cannot determine type - skip filter
        }

        // Check allowedTypes
        if (null !== $this->allowedTypes && !\in_array($fileType, $this->allowedTypes, true)) {
            return true; // Type not in allowed list
        }

        // Check deniedTypes
        if (null !== $this->deniedTypes && \in_array($fileType, $this->deniedTypes, true)) {
            return true; // Type in denied list
        }

        return false; // Allow processing
    }

    #[\Override]
    public function getName(): string
    {
        return 'file_type';
    }

    #[\Override]
    public function canFilterEarly(): bool
    {
        return false; // Needs metadata to determine type
    }
}
