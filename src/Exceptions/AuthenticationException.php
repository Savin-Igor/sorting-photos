<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Exceptions;

/**
 * Exception for authentication and token errors.
 */
final class AuthenticationException extends \RuntimeException
{
    private const string NO_REFRESH_TOKEN = 'No refresh token available. Please run: php bin/console google-photos:authorize';
    private const string NO_ACCESS_TOKEN = 'No access_token in response from UserRefreshCredentials';
    private const string NO_VALID_ACCESS_TOKEN = 'No valid access token available. Please run: php bin/console google-photos:authorize';
    private const string FAILED_EXCHANGE_CODE = 'Failed to exchange authorization code: no access_token in response';
    private const string INVALID_CREDENTIALS_FILE = 'Invalid credentials file: %s';
    private const string CREDENTIALS_FILE_NOT_FOUND = 'Credentials file not found: %s. Please ensure the file exists and is mounted correctly. Set GOOGLE_PHOTOS_CREDENTIALS_DIR_HOST in .env to point to the directory containing credentials.json';
    private const string FAILED_READ_CREDENTIALS_FILE = 'Failed to read credentials file: %s';
    private const string INVALID_JSON_IN_CREDENTIALS_FILE = 'Invalid JSON in credentials file: %s';
    private const string INVALID_CREDENTIALS_FILE_FORMAT = 'Invalid credentials file format: missing "installed" or "web" key';

    public static function noRefreshToken(): self
    {
        return new self(self::NO_REFRESH_TOKEN);
    }

    public static function noAccessToken(): self
    {
        return new self(self::NO_ACCESS_TOKEN);
    }

    public static function noValidAccessToken(): self
    {
        return new self(self::NO_VALID_ACCESS_TOKEN);
    }

    public static function failedExchangeCode(): self
    {
        return new self(self::FAILED_EXCHANGE_CODE);
    }

    public static function invalidCredentialsFile(string $reason): self
    {
        return new self(\sprintf(self::INVALID_CREDENTIALS_FILE, $reason));
    }

    public static function credentialsFileNotFound(string $path): self
    {
        return new self(\sprintf(self::CREDENTIALS_FILE_NOT_FOUND, $path));
    }

    public static function failedReadCredentialsFile(string $path): self
    {
        return new self(\sprintf(self::FAILED_READ_CREDENTIALS_FILE, $path));
    }

    public static function invalidJsonInCredentialsFile(string $path): self
    {
        return new self(\sprintf(self::INVALID_JSON_IN_CREDENTIALS_FILE, $path));
    }

    public static function invalidCredentialsFileFormat(): self
    {
        return new self(self::INVALID_CREDENTIALS_FILE_FORMAT);
    }
}
