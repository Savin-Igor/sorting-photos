<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Filter\Filter;

use SortingPhotosByDate\Application\Filter\FileFilterInterface;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

/**
 * Filter by MIME type.
 */
final readonly class MimeTypeFilter implements FileFilterInterface
{
    /**
     * @param array<string>|null $allowedMimeTypes Allowed MIME types ['image/jpeg', 'image/png'] or null
     * @param array<string>|null $deniedMimeTypes  Denied MIME types ['image/gif'] or null
     * @param array<string>|null $allowedPatterns  Allowed patterns ['image/*'] or null
     * @param array<string>|null $deniedPatterns   Denied patterns ['image/gif'] or null
     */
    public function __construct(
        private ?array $allowedMimeTypes = null,
        private ?array $deniedMimeTypes = null,
        private ?array $allowedPatterns = null,
        private ?array $deniedPatterns = null,
    ) {
    }

    #[\Override]
    public function shouldSkip(FilePath $filePath, array $context = []): bool
    {
        $mimeType = $context['mime_type'] ?? null;
        if (null === $mimeType || !\is_string($mimeType)) {
            return false; // No MIME type - skip filter
        }

        // Check deniedMimeTypes (exact match)
        if (null !== $this->deniedMimeTypes && \in_array($mimeType, $this->deniedMimeTypes, true)) {
            return true;
        }

        // Check deniedPatterns (wildcard, e.g., 'image/gif')
        if (null !== $this->deniedPatterns) {
            foreach ($this->deniedPatterns as $pattern) {
                if ($this->matchesPattern($mimeType, $pattern)) {
                    return true;
                }
            }
        }

        // Check allowedMimeTypes (if list is specified)
        if (null !== $this->allowedMimeTypes && !\in_array($mimeType, $this->allowedMimeTypes, true)) {
            // Check patterns
            $matchesPattern = false;
            if (null !== $this->allowedPatterns) {
                foreach ($this->allowedPatterns as $pattern) {
                    if ($this->matchesPattern($mimeType, $pattern)) {
                        $matchesPattern = true;
                        break;
                    }
                }
            }
            if (!$matchesPattern) {
                return true; // Not in allowed list
            }
        }

        return false; // Allow processing
    }

    /**
     * Check if MIME type matches pattern (supports wildcard).
     */
    private function matchesPattern(string $mimeType, string $pattern): bool
    {
        // Support wildcard: 'image/*' matches 'image/jpeg', 'image/png', etc.
        $patternRegex = '/^'.\str_replace('*', '.*', \preg_quote($pattern, '/')).'$/';

        return (bool) \preg_match($patternRegex, $mimeType);
    }

    #[\Override]
    public function getName(): string
    {
        return 'mime_type';
    }

    #[\Override]
    public function canFilterEarly(): bool
    {
        return false; // Needs metadata to get MIME type
    }
}
