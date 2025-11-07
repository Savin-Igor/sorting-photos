<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use Google\Auth\Credentials\UserRefreshCredentials;
use SortingPhotosByDate\Ports\LoggerPort;

final class TokenManager
{
    private ?UserRefreshCredentials $credentials = null;

    public function __construct(
        private readonly CredentialsFactory $credentialsFactory,
        private readonly TokenStorage $tokenStorage,
        private readonly LoggerPort $logger,
        private readonly bool $fullAccess = false,
        private readonly ?string $fallbackAccessToken = null,
    ) {
    }

    /**
     * Get valid access token, refreshing if necessary.
     */
    public function getValidAccessToken(): string
    {
        // Get credentials from cache or create new ones
        if (!$this->credentials instanceof UserRefreshCredentials) {
            $refreshToken = $this->tokenStorage->getRefreshToken();

            if (null !== $refreshToken) {
                // Reset credentials cache to recreate with correct scope
                $this->credentials = null;
                $this->credentials = $this->credentialsFactory->createUserRefreshCredentials(
                    $refreshToken,
                    $this->fullAccess
                );
                $this->logger->info('Created UserRefreshCredentials', [
                    'fullAccess' => $this->fullAccess,
                    'scope' => $this->fullAccess ? 'photoslibrary' : 'photoslibrary.appendonly',
                ]);
            }
        }

        // If credentials exist, use them to get token
        if ($this->credentials instanceof UserRefreshCredentials) {
            try {
                // UserRefreshCredentials uses fetchAuthToken() to get token
                $tokenData = $this->credentials->fetchAuthToken();

                if (!isset($tokenData['access_token'])) {
                    throw new \RuntimeException('No access_token in response from UserRefreshCredentials');
                }

                $accessToken = $tokenData['access_token'];
                $this->logger->debug('Access token obtained from UserRefreshCredentials');
                
                // Log scope from token response if available
                if (isset($tokenData['scope'])) {
                    $this->logger->info('Access token scope from Google', [
                        'scope' => $tokenData['scope'],
                        'requested_scope' => $this->fullAccess ? 'photoslibrary' : 'photoslibrary.appendonly',
                        'fullAccess' => $this->fullAccess,
                    ]);
                } else {
                    $this->logger->warning('No scope in token response from Google', [
                        'token_keys' => array_keys($tokenData),
                        'requested_scope' => $this->fullAccess ? 'photoslibrary' : 'photoslibrary.appendonly',
                    ]);
                }

                return $accessToken;
            } catch (\Exception $e) {
                $this->logger->error('Failed to get access token from credentials', [
                    'error' => $e->getMessage(),
                ]);

                // If token is revoked or expired (invalid_grant), clear it
                if (str_contains($e->getMessage(), 'invalid_grant') || str_contains($e->getMessage(), 'expired') || str_contains($e->getMessage(), 'revoked')) {
                    $this->logger->warning('Refresh token expired or revoked, clearing it');
                    $this->tokenStorage->deleteRefreshToken(); // Delete token
                    $this->credentials = null; // Reset cache
                }

                // If credentials are invalid, try fallback
            }
        }

        // Fallback to environment variable (for backward compatibility)
        if (null !== $this->fallbackAccessToken && '' !== $this->fallbackAccessToken) {
            $this->logger->warning('Using fallback access token from environment variable');

            return $this->fallbackAccessToken;
        }

        throw new \RuntimeException('No valid access token available. Please run: php bin/console google-photos:authorize');
    }

    /**
     * Save refresh token after successful authorization.
     */
    public function saveRefreshToken(string $refreshToken): void
    {
        $this->tokenStorage->saveRefreshToken($refreshToken);
        // Reset credentials cache to use new refresh token
        $this->credentials = null;
        $this->logger->info('Refresh token saved successfully');
    }

    /**
     * Check if saved refresh token exists.
     */
    public function hasRefreshToken(): bool
    {
        return $this->tokenStorage->hasRefreshToken();
    }

    /**
     * Delete refresh token (for re-authorization).
     */
    public function deleteRefreshToken(): void
    {
        $this->tokenStorage->deleteRefreshToken();
        $this->credentials = null; // Reset cache
        $this->logger->info('Refresh token deleted');
    }
}
