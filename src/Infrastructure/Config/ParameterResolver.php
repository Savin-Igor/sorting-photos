<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Config;

/**
 * Helper class for loading and resolving application parameters from environment variables.
 */
final readonly class ParameterResolver
{
    /**
     * Resolve application parameters from environment variables.
     * Only returns values for environment variables that are actually set.
     *
     * @return array<string, mixed> Array of parameter name => value (only for set env vars)
     */
    public static function resolveFromEnvironment(): array
    {
        $parameters = [];

        // Required parameters - always include if set
        $sourceDir = self::getEnv('SOURCE_DIRECTORY', '');
        if ('' !== $sourceDir) {
            $parameters['app.source_directory'] = $sourceDir;
        }

        $destDir = self::getEnv('DESTINATION_DIRECTORY', '');
        if ('' !== $destDir) {
            $parameters['app.destination_directory'] = $destDir;
        }

        // Optional parameters - only include if env var is set
        $organizerPolicy = self::getEnv('ORGANIZER_POLICY', '');
        if ('' !== $organizerPolicy) {
            $parameters['app.organizer_policy'] = $organizerPolicy;
        }

        $dryRun = self::getEnv('DRY_RUN', '');
        if ('' !== $dryRun) {
            $parameters['app.dry_run'] = filter_var($dryRun, FILTER_VALIDATE_BOOLEAN);
        }

        $dirPerms = self::getEnv('FILESYSTEM_DEFAULT_DIR_PERMISSIONS', '');
        if ('' !== $dirPerms) {
            $parameters['app.filesystem.default_dir_permissions'] = (int) $dirPerms;
        }

        $accessToken = self::getEnv('GOOGLE_PHOTOS_ACCESS_TOKEN', '');
        if ('' !== $accessToken) {
            $parameters['app.google_photos.access_token'] = $accessToken;
        }

        $albumId = self::getEnv('GOOGLE_PHOTOS_ALBUM_ID', '');
        if ('' !== $albumId) {
            $parameters['app.google_photos.album_id'] = $albumId;
        }

        $credentialsPath = self::getEnv('GOOGLE_PHOTOS_CREDENTIALS_PATH', '');
        if ('' !== $credentialsPath) {
            $parameters['app.google_photos.credentials_path'] = $credentialsPath;
        }

        $fullAccess = self::getEnv('GOOGLE_PHOTOS_FULL_ACCESS', '');
        if ('' !== $fullAccess) {
            $parameters['app.google_photos.full_access'] = filter_var($fullAccess, FILTER_VALIDATE_BOOLEAN);
        }

        // Compression settings
        $compressionEnabled = self::getEnv('COMPRESSION_ENABLED', '');
        if ('' !== $compressionEnabled) {
            $parameters['app.compression.enabled'] = filter_var($compressionEnabled, FILTER_VALIDATE_BOOLEAN);
        }

        $jpegMaxPixels = self::getEnv('COMPRESSION_JPEG_MAX_PIXELS', '');
        if ('' !== $jpegMaxPixels) {
            $parameters['app.compression.jpeg_max_pixels'] = (int) $jpegMaxPixels;
        }

        $pngMaxPixels = self::getEnv('COMPRESSION_PNG_MAX_PIXELS', '');
        if ('' !== $pngMaxPixels) {
            $parameters['app.compression.png_max_pixels'] = (int) $pngMaxPixels;
        }

        $videoMaxSize = self::getEnv('COMPRESSION_VIDEO_MAX_SIZE_BYTES', '');
        if ('' !== $videoMaxSize) {
            $parameters['app.compression.video_max_size_bytes'] = (int) $videoMaxSize;
        }

        $minFileSize = self::getEnv('COMPRESSION_MIN_FILE_SIZE_BYTES', '');
        if ('' !== $minFileSize) {
            $parameters['app.compression.min_file_size_bytes'] = (int) $minFileSize;
        }

        // Logging settings - only override if env vars are set
        $logFileEnabled = self::getEnv('LOG_FILE_ENABLED', '');
        if ('' !== $logFileEnabled) {
            $parameters['app.logging.file_enabled'] = filter_var($logFileEnabled, FILTER_VALIDATE_BOOLEAN);
        }

        $logFileLevel = self::getEnv('LOG_FILE_LEVEL', '');
        if ('' !== $logFileLevel) {
            $parameters['app.logging.file_level'] = strtoupper($logFileLevel);
        }

        $logConsoleLevel = self::getEnv('LOG_CONSOLE_LEVEL', '');
        if ('' !== $logConsoleLevel) {
            $parameters['app.logging.console_level'] = strtoupper($logConsoleLevel);
        }

        return $parameters;
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
