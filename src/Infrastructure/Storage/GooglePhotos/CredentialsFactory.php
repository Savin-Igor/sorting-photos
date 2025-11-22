<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use Google\Auth\Credentials\UserRefreshCredentials;
use Google\Auth\OAuth2;
use SortingPhotosByDate\Exceptions\AuthenticationException;
use SortingPhotosByDate\Exceptions\ValidationException;
use SortingPhotosByDate\Ports\LoggerPort;

final readonly class CredentialsFactory
{
    private const string SCOPE_APPEND_ONLY = 'https://www.googleapis.com/auth/photoslibrary.appendonly';
    private const string SCOPE_FULL_ACCESS = 'https://www.googleapis.com/auth/photoslibrary';

    public function __construct(
        private string $credentialsPath,
        private LoggerPort $logger,
    ) {
        if ('' === $this->credentialsPath) {
            throw ValidationException::emptyValue('Credentials path');
        }

        // Don't check file existence here - check will be done when used
        // This allows service initialization even if file is not created yet
    }

    /**
     * Create OAuth2 client for authorization.
     */
    public function createOAuth2Client(bool $fullAccess = false): OAuth2
    {
        $credentials = $this->loadCredentials();
        $scope = $fullAccess ? self::SCOPE_FULL_ACCESS : self::SCOPE_APPEND_ONLY;

        // Determine redirect URI based on client type
        // Desktop app (installed) -> OOB (code on page) - still works for Desktop app
        // Web app -> use first from redirect_uris or http://localhost:8080/oauth/callback
        if (isset($credentials['installed'])) {
            // Desktop app - use OOB (still supported for Desktop app)
            $redirectUri = $credentials['installed']['redirect_uris'][0] ?? 'urn:ietf:wg:oauth:2.0:oob';
        } elseif (isset($credentials['web'])) {
            // Web app - use first configured or default
            // IMPORTANT: this URI must be added to Google Cloud Console!
            $redirectUri = $credentials['web']['redirect_uris'][0] ?? 'http://localhost:8080/oauth/callback';
        } else {
            // Fallback - use OOB for Desktop app
            $redirectUri = 'urn:ietf:wg:oauth:2.0:oob';
        }

        return new OAuth2([
            'clientId' => $credentials['installed']['client_id'] ?? $credentials['web']['client_id'] ?? '',
            'clientSecret' => $credentials['installed']['client_secret'] ?? $credentials['web']['client_secret'] ?? '',
            'redirectUri' => $redirectUri,
            'scope' => $scope,
            'tokenCredentialUri' => 'https://oauth2.googleapis.com/token',
            'authorizationUri' => 'https://accounts.google.com/o/oauth2/v2/auth',
        ]);
    }

    /**
     * Create UserRefreshCredentials from saved refresh token.
     */
    public function createUserRefreshCredentials(?string $refreshToken, bool $fullAccess = false): ?UserRefreshCredentials
    {
        if (null === $refreshToken || '' === $refreshToken) {
            return null;
        }

        $credentials = $this->loadCredentials();
        $scope = $fullAccess ? self::SCOPE_FULL_ACCESS : self::SCOPE_APPEND_ONLY;

        $clientId = $credentials['installed']['client_id'] ?? $credentials['web']['client_id'] ?? '';
        $clientSecret = $credentials['installed']['client_secret'] ?? $credentials['web']['client_secret'] ?? '';

        if ('' === $clientId || '' === $clientSecret) {
            throw AuthenticationException::invalidCredentialsFile('missing client_id or client_secret');
        }

        return new UserRefreshCredentials(
            $scope,
            [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ]
        );
    }

    /**
     * Get authorization URL.
     */
    public function getAuthorizationUrl(bool $fullAccess = false): string
    {
        $oauth2 = $this->createOAuth2Client($fullAccess);

        // Set state for CSRF protection
        $state = \bin2hex(\random_bytes(16));
        $oauth2->setState($state);

        // buildFullAuthorizationUri already sets access_type='offline' by default
        // Pass prompt via config to get refresh token
        $authUri = $oauth2->buildFullAuthorizationUri([
            'prompt' => 'consent', // Force consent prompt to get refresh token
        ]);

        return (string) $authUri;
    }

    /**
     * Exchange authorization code for tokens using Google Auth Library.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function exchangeCodeForTokens(string $authorizationCode, bool $fullAccess = false): array
    {
        $oauth2 = $this->createOAuth2Client($fullAccess);
        $oauth2->setCode($authorizationCode);

        // fetchAuthToken automatically uses access_type='offline' from buildFullAuthorizationUri
        $authToken = $oauth2->fetchAuthToken();

        if (!isset($authToken['access_token'])) {
            throw AuthenticationException::failedExchangeCode();
        }

        $this->logger->info('Successfully exchanged authorization code for tokens', [
            'has_refresh_token' => isset($authToken['refresh_token']),
            'expires_in' => $authToken['expires_in'] ?? 'unknown',
        ]);

        return [
            'access_token' => $authToken['access_token'],
            'refresh_token' => $authToken['refresh_token'] ?? '',
            'expires_in' => (int) ($authToken['expires_in'] ?? 3600),
        ];
    }

    /**
     * Load credentials.json file.
     *
     * @return array<string, mixed>
     */
    private function loadCredentials(): array
    {
        if (!\file_exists($this->credentialsPath)) {
            throw AuthenticationException::credentialsFileNotFound($this->credentialsPath);
        }

        $content = \file_get_contents($this->credentialsPath);
        if (false === $content) {
            throw AuthenticationException::failedReadCredentialsFile($this->credentialsPath);
        }

        $credentials = \json_decode($content, true);
        if (false === $credentials || null === $credentials) {
            throw AuthenticationException::invalidJsonInCredentialsFile($this->credentialsPath);
        }

        // Check credentials.json format
        if (!isset($credentials['installed']) && !isset($credentials['web'])) {
            throw AuthenticationException::invalidCredentialsFileFormat();
        }

        return $credentials;
    }
}
