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
