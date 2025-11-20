<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Filter;

use SortingPhotosByDate\Application\Filter\FilterChain;
use SortingPhotosByDate\Application\Filter\Filter\DateFilter;
use SortingPhotosByDate\Application\Filter\Filter\FileSizeFilter;
use SortingPhotosByDate\Application\Filter\Filter\FileTypeFilter;
use SortingPhotosByDate\Application\Filter\Filter\FilenameRegexFilter;
use SortingPhotosByDate\Application\Filter\Filter\MimeTypeFilter;
use SortingPhotosByDate\Infrastructure\Metadata\FilenameDateExtractor;
use SortingPhotosByDate\Ports\LoggerPort;

/**
 * Factory for creating FilterChain based on configuration.
 */
final readonly class FilterChainFactory
{
    public function __construct(
        private FilenameDateExtractor $filenameDateExtractor,
        private LoggerPort $logger,
    ) {
    }

    /**
     * Create FilterChain from configuration parameters.
     *
     * @param array<string, mixed> $config Filter configuration
     *
     * @return FilterChain|null FilterChain instance or null if filters disabled
     */
    public function create(array $config): ?FilterChain
    {
        if (!($config['enabled'] ?? false)) {
            return null;
        }

        $filters = [];

        // FileTypeFilter
        if ($config['file_type']['enabled'] ?? false) {
            $allowedTypes = $config['file_type']['allowed'] ?? null;
            $deniedTypes = $config['file_type']['denied'] ?? null;
            $filters[] = new FileTypeFilter(
                allowedTypes: \is_array($allowedTypes) ? \array_values(\array_filter($allowedTypes, is_string(...))) : null,
                deniedTypes: \is_array($deniedTypes) ? \array_values(\array_filter($deniedTypes, is_string(...))) : null,
            );
        }

        // MimeTypeFilter
        if ($config['mime_type']['enabled'] ?? false) {
            $allowedMimeTypes = $config['mime_type']['allowed'] ?? null;
            $deniedMimeTypes = $config['mime_type']['denied'] ?? null;
            $allowedPatterns = $config['mime_type']['allowed_patterns'] ?? null;
            $deniedPatterns = $config['mime_type']['denied_patterns'] ?? null;
            $filters[] = new MimeTypeFilter(
                allowedMimeTypes: \is_array($allowedMimeTypes) ? \array_values(\array_filter($allowedMimeTypes, is_string(...))) : null,
                deniedMimeTypes: \is_array($deniedMimeTypes) ? \array_values(\array_filter($deniedMimeTypes, is_string(...))) : null,
                allowedPatterns: \is_array($allowedPatterns) ? \array_values(\array_filter($allowedPatterns, is_string(...))) : null,
                deniedPatterns: \is_array($deniedPatterns) ? \array_values(\array_filter($deniedPatterns, is_string(...))) : null,
            );
        }

        // FileSizeFilter
        if ($config['file_size']['enabled'] ?? false) {
            $minBytes = $config['file_size']['min_bytes'] ?? null;
            $maxBytes = $config['file_size']['max_bytes'] ?? null;
            $filters[] = new FileSizeFilter(
                minSizeBytes: (\is_int($minBytes) || (\is_string($minBytes) && \is_numeric($minBytes))) ? (int) $minBytes : null,
                maxSizeBytes: (\is_int($maxBytes) || (\is_string($maxBytes) && \is_numeric($maxBytes))) ? (int) $maxBytes : null,
            );
        }

        // FilenameRegexFilter
        if ($config['filename_regex']['enabled'] ?? false) {
            $patterns = $config['filename_regex']['patterns'] ?? [];
            if (\is_array($patterns) && [] !== $patterns) {
                $stringPatterns = \array_values(\array_filter($patterns, is_string(...)));
                if ([] !== $stringPatterns) {
                    $filters[] = new FilenameRegexFilter(patterns: $stringPatterns);
                }
            }
        }

        // DateFilter
        if ($config['date']['enabled'] ?? false) {
            $fromDate = null;
            $toDate = null;

            $fromDateStr = $config['date']['from_date'] ?? null;
            if (null !== $fromDateStr && \is_string($fromDateStr) && '' !== $fromDateStr) {
                $fromDate = $this->parseDate($fromDateStr);
            }

            $toDateStr = $config['date']['to_date'] ?? null;
            if (null !== $toDateStr && \is_string($toDateStr) && '' !== $toDateStr) {
                $toDate = $this->parseDate($toDateStr);
            }

            $checkFilename = $config['date']['check_filename'] ?? true;
            $checkMetadata = $config['date']['check_metadata'] ?? true;

            $filters[] = new DateFilter(
                filenameDateExtractor: $this->filenameDateExtractor,
                fromDate: $fromDate,
                toDate: $toDate,
                checkFilename: \is_bool($checkFilename) ? $checkFilename : true,
                checkMetadata: \is_bool($checkMetadata) ? $checkMetadata : true,
            );
        }

        if ([] === $filters) {
            return null; // No filters enabled
        }

        return new FilterChain($filters, $this->logger);
    }

    /**
     * Parse date string to DateTimeImmutable.
     */
    private function parseDate(?string $dateString): ?\DateTimeImmutable
    {
        if (null === $dateString || '' === $dateString) {
            return null;
        }

        try {
            return new \DateTimeImmutable($dateString);
        } catch (\Exception) {
            $this->logger->warning('Invalid date format in filter configuration', [
                'date_string' => $dateString,
            ]);

            return null;
        }
    }
}
