<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Config;

/**
 * Helper class for loading and resolving application parameters from environment variables.
 */
final readonly class ParameterResolver
{
    /**
     * Resolve application parameters from environment variables with defaults.
     *
     * @return array<string, mixed> Array of parameter name => value
     */
    public static function resolveFromEnvironment(): array
    {
        $albumId = self::getEnv('GOOGLE_PHOTOS_ALBUM_ID', '');

        return [
            'app.source_directory' => self::getEnv('SOURCE_DIRECTORY', ''),
            'app.destination_directory' => self::getEnv('DESTINATION_DIRECTORY', ''),
            'app.organizer_policy' => self::getEnv('ORGANIZER_POLICY', 'date-type'),
            'app.dry_run' => filter_var(self::getEnv('DRY_RUN', 'false'), FILTER_VALIDATE_BOOLEAN),
            'app.filesystem.default_dir_permissions' => (int) self::getEnv('FILESYSTEM_DEFAULT_DIR_PERMISSIONS', '0755'),
            'app.google_photos.access_token' => self::getEnv('GOOGLE_PHOTOS_ACCESS_TOKEN', ''),
            'app.google_photos.album_id' => '' !== $albumId ? $albumId : null,
            'app.google_photos.credentials_path' => self::getEnv('GOOGLE_PHOTOS_CREDENTIALS_PATH', ''),
            'app.google_photos.full_access' => filter_var(self::getEnv('GOOGLE_PHOTOS_FULL_ACCESS', 'false'), FILTER_VALIDATE_BOOLEAN),
            // Compression settings
            'app.compression.enabled' => filter_var(self::getEnv('COMPRESSION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
            'app.compression.jpeg_max_pixels' => (int) self::getEnv('COMPRESSION_JPEG_MAX_PIXELS', '75000000'),
            'app.compression.png_max_pixels' => (int) self::getEnv('COMPRESSION_PNG_MAX_PIXELS', '200000000'),
            'app.compression.video_max_size_bytes' => (int) self::getEnv('COMPRESSION_VIDEO_MAX_SIZE_BYTES', '10737418240'), // 10 GB
            // Logging settings
            'app.logging.file_enabled' => filter_var(self::getEnv('LOG_FILE_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
            'app.logging.file_level' => strtoupper(self::getEnv('LOG_FILE_LEVEL', 'DEBUG')),
            'app.logging.console_level' => strtoupper(self::getEnv('LOG_CONSOLE_LEVEL', 'INFO')),
        ];
    }

    /**
     * Get environment variable value or return default.
     *
     * @return string Environment variable value or default
     */
    private static function getEnv(string $name, string $default = ''): string
    {
        /** @var string|false $envValue */
        $envValue = $_ENV[$name] ?? getenv($name);

        return false !== $envValue ? $envValue : $default;
    }
}
