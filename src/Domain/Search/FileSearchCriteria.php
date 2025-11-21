<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Search;

/**
 * Value Object representing search criteria for file search.
 * Validates regex patterns at construction time.
 */
final readonly class FileSearchCriteria
{
    /**
     * @param string[] $extensions         File extensions (without dot)
     * @param int|null $minSizeBytes       Minimum file size in bytes
     * @param int|null $maxSizeBytes       Maximum file size in bytes
     * @param string[] $filenamePatterns   Regex patterns for filename matching
     * @param string[] $excludeDirectories Glob patterns for directory exclusion
     * @param string[] $excludePatterns    Glob patterns for file exclusion
     * @param string[] $includePatterns    Glob patterns for file inclusion
     */
    public function __construct(
        private array $extensions = [],
        private ?int $minSizeBytes = null,
        private ?int $maxSizeBytes = null,
        private array $filenamePatterns = [],
        private array $excludeDirectories = [],
        private array $excludePatterns = [],
        private array $includePatterns = [],
    ) {
        // Validate regex patterns at construction time
        $this->validateRegexPatterns($this->filenamePatterns);

        // Validate size constraints
        if (null !== $this->minSizeBytes && $this->minSizeBytes < 0) {
            throw new \InvalidArgumentException('minSizeBytes must be non-negative');
        }
        if (null !== $this->maxSizeBytes && $this->maxSizeBytes < 0) {
            throw new \InvalidArgumentException('maxSizeBytes must be non-negative');
        }
        if (null !== $this->minSizeBytes && null !== $this->maxSizeBytes && $this->minSizeBytes > $this->maxSizeBytes) {
            throw new \InvalidArgumentException('minSizeBytes cannot be greater than maxSizeBytes');
        }
    }

    /**
     * Create FileSearchCriteria from configuration array.
     *
     * @param array<string, mixed> $config Configuration array
     */
    public static function fromConfig(array $config): self
    {
        $extensions = \is_array($config['extensions'] ?? null)
            ? \array_filter($config['extensions'], is_string(...))
            : [];

        $minSizeBytes = isset($config['min_size_bytes']) && \is_int($config['min_size_bytes'])
            ? $config['min_size_bytes']
            : null;

        $maxSizeBytes = isset($config['max_size_bytes']) && \is_int($config['max_size_bytes'])
            ? $config['max_size_bytes']
            : null;

        $filenamePatterns = \is_array($config['filename_patterns'] ?? null)
            ? \array_filter($config['filename_patterns'], is_string(...))
            : [];

        $excludeDirectories = \is_array($config['exclude_directories'] ?? null)
            ? \array_filter($config['exclude_directories'], is_string(...))
            : [];

        $excludePatterns = \is_array($config['exclude_patterns'] ?? null)
            ? \array_filter($config['exclude_patterns'], is_string(...))
            : [];

        $includePatterns = \is_array($config['include_patterns'] ?? null)
            ? \array_filter($config['include_patterns'], is_string(...))
            : [];

        return new self(
            extensions: $extensions,
            minSizeBytes: $minSizeBytes,
            maxSizeBytes: $maxSizeBytes,
            filenamePatterns: $filenamePatterns,
            excludeDirectories: $excludeDirectories,
            excludePatterns: $excludePatterns,
            includePatterns: $includePatterns,
        );
    }

    /**
     * Validate regex patterns at construction time.
     *
     * @param string[] $patterns Regex patterns to validate
     *
     * @throws \InvalidArgumentException If any pattern is invalid
     */
    private function validateRegexPatterns(array $patterns): void
    {
        foreach ($patterns as $pattern) {
            if (!\is_string($pattern) || '' === $pattern) {
                continue;
            }

            // Test regex pattern
            $testResult = @\preg_match($pattern, '');
            if (false === $testResult) {
                $error = \preg_last_error();
                $errorMsg = match ($error) {
                    \PREG_INTERNAL_ERROR => 'Internal regex error',
                    \PREG_BACKTRACK_LIMIT_ERROR => 'Backtrack limit error',
                    \PREG_RECURSION_LIMIT_ERROR => 'Recursion limit error',
                    \PREG_BAD_UTF8_ERROR => 'Bad UTF-8 error',
                    \PREG_BAD_UTF8_OFFSET_ERROR => 'Bad UTF-8 offset error',
                    default => 'Unknown regex error',
                };
                throw new \InvalidArgumentException(\sprintf('Invalid regex pattern "%s": %s', $pattern, $errorMsg));
            }
        }
    }

    /**
     * @return string[]
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    public function getMinSizeBytes(): ?int
    {
        return $this->minSizeBytes;
    }

    public function getMaxSizeBytes(): ?int
    {
        return $this->maxSizeBytes;
    }

    /**
     * @return string[] Regex patterns (validated at construction time)
     */
    public function getFilenamePatterns(): array
    {
        return $this->filenamePatterns;
    }

    /**
     * @return string[]
     */
    public function getExcludeDirectories(): array
    {
        return $this->excludeDirectories;
    }

    /**
     * @return string[]
     */
    public function getExcludePatterns(): array
    {
        return $this->excludePatterns;
    }

    /**
     * @return string[]
     */
    public function getIncludePatterns(): array
    {
        return $this->includePatterns;
    }

    /**
     * Check if criteria has any filters.
     */
    public function hasFilters(): bool
    {
        return [] !== $this->extensions
            || null !== $this->minSizeBytes
            || null !== $this->maxSizeBytes
            || [] !== $this->filenamePatterns
            || [] !== $this->excludeDirectories
            || [] !== $this->excludePatterns
            || [] !== $this->includePatterns;
    }
}
