<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\FileValidation;

/**
 * Validates file extensions for media files and search criteria.
 */
final readonly class FileExtensionValidator implements FileExtensionValidatorInterface
{
    /**
     * Common non-media file extensions that should never be uploaded to Google Photos.
     */
    private const array NON_MEDIA_EXTENSIONS = [
        // Code files
        'php', 'js', 'ts', 'jsx', 'tsx', 'py', 'java', 'cpp', 'c', 'h', 'hpp', 'cs', 'go', 'rs', 'rb', 'swift', 'kt',
        // Config/data files
        'json', 'xml', 'yaml', 'yml', 'toml', 'ini', 'conf', 'config', 'env', 'properties',
        // Text files
        'txt', 'md', 'markdown', 'readme', 'log', 'csv', 'tsv',
        // Archive files
        'zip', 'tar', 'gz', 'bz2', 'xz', '7z', 'rar', 'cab',
        // Executable files
        'exe', 'dll', 'so', 'dylib', 'bin', 'sh', 'bat', 'cmd', 'ps1',
        // Database files
        'db', 'sqlite', 'sql', 'sqlite3',
        // Document files (not supported by Google Photos)
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf',
        // Font files
        'ttf', 'otf', 'woff', 'woff2', 'eot',
        // Other
        'lock', 'cache', 'tmp', 'temp', 'bak', 'backup', 'old',
    ];

    public function matchesSearchExtensions(string $filePath, ?array $searchConfig): bool
    {
        // If no search config or no extensions specified, accept all files
        if (null === $searchConfig || empty($searchConfig['extensions'])) {
            return true;
        }

        $extensions = $searchConfig['extensions'];
        if (!\is_array($extensions)) {
            return true;
        }

        $extension = \strtolower(\pathinfo($filePath, \PATHINFO_EXTENSION));
        $allowedExtensions = \array_map(\strtolower(...), \array_filter($extensions, is_string(...)));

        // If no valid extensions after filtering, accept all files
        if ([] === $allowedExtensions) {
            return true;
        }

        return \in_array($extension, $allowedExtensions, true);
    }

    public function isNonMediaExtension(string $filePath): bool
    {
        $extension = \strtolower(\pathinfo($filePath, \PATHINFO_EXTENSION));

        return \in_array($extension, self::NON_MEDIA_EXTENSIONS, true);
    }
}
