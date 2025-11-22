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
}
