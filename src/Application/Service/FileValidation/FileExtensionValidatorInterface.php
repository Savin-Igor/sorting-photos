<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\FileValidation;

/**
 * Interface for validating file extensions.
 */
interface FileExtensionValidatorInterface
{
    /**
     * Check if file extension matches search configuration extensions.
     * If search config specifies extensions, only files with those extensions are allowed.
     *
     * @param string               $filePath     Full path to the file
     * @param array<string, mixed> $searchConfig Search configuration (may contain 'extensions' key)
     *
     * @return bool True if file extension matches search config or if no extensions are specified
     */
    public function matchesSearchExtensions(string $filePath, ?array $searchConfig): bool;

    /**
     * Check if file has a non-media extension that should be skipped early.
     *
     * @param string $filePath Full path to the file
     *
     * @return bool True if file should be skipped based on extension
     */
    public function isNonMediaExtension(string $filePath): bool;
}
