<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage;

use SortingPhotosByDate\Domain\Storage\StorageType;
use SortingPhotosByDate\Exceptions\ConfigurationException;

/**
 * Storage configuration value object.
 */
final readonly class StorageConfiguration
{
    /**
     * @param array<string, mixed> $credentials Storage credentials
     * @param array<string, mixed> $options     Storage-specific options
     */
    public function __construct(
        public StorageType $type,
        public array $credentials = [],
        public ?RateLimitConfiguration $rateLimit = null,
        public array $options = [],
    ) {
        $this->validate();
    }

    /**
     * Create configuration from DSN string.
     *
     * @param string $dsn DSN string (e.g., "google-photos://album-id?client_id=xxx&client_secret=yyy")
     *
     * @return self Storage configuration
     */
    public static function fromDsn(string $dsn): self
    {
        // Handle triple slash for absolute paths (e.g., local:///path/to/dir)
        // Extract path directly from DSN for triple-slash case before parsing
        $location = '';
        if (preg_match('#^([^:]+)://(/.*)$#', $dsn, $matches)) {
            $scheme = $matches[1];
            $location = $matches[2];
            $normalizedDsn = $scheme.'://host'.$location; // Use host placeholder for parse_url
        } else {
            $normalizedDsn = $dsn;
        }

        $parsed = parse_url($normalizedDsn);

        if (false === $parsed || !isset($parsed['scheme'])) {
            throw ConfigurationException::invalidDsn($dsn);
        }

        $scheme = $parsed['scheme'];
        $type = StorageType::fromDsnScheme($scheme);

        // Parse query parameters
        $credentials = [];
        $options = [];
        $rateLimit = null;

        if (isset($parsed['query'])) {
            $params = [];
            parse_str($parsed['query'], $params);
            /** @var array<string, string> $params */

            // Extract rate limit parameters
            if (isset($params['rate_limit_requests'], $params['rate_limit_per_seconds'])) {
                $rateLimit = new RateLimitConfiguration(
                    requests: (int) $params['rate_limit_requests'],
                    perSeconds: (int) $params['rate_limit_per_seconds'],
                    burst: isset($params['rate_limit_burst']) ? (int) $params['rate_limit_burst'] : null,
                );
                unset($params['rate_limit_requests'], $params['rate_limit_per_seconds'], $params['rate_limit_burst']);
            }

            // Separate credentials and options (credentials are typically auth-related)
            $credentialKeys = ['client_id', 'client_secret', 'access_token', 'refresh_token', 'access_key', 'secret_key', 'api_key', 'api_secret'];
            foreach ($params as $key => $value) {
                if (in_array($key, $credentialKeys, true)) {
                    $credentials[$key] = $value;
                } else {
                    $options[$key] = $value;
                }
            }
        }

        // Path/authority becomes location option
        // Use extracted location if we have it (triple-slash case), otherwise use parsed path
        if ('' === $location) {
            if (isset($parsed['path'])) {
                $location = $parsed['path'];
            } elseif (isset($parsed['host'])) {
                $location = $parsed['host'];
            }
        }

        if ('' !== $location) {
            // For local storage, path should be absolute, so keep the leading slash
            if (StorageType::LOCAL === $type && str_starts_with($location, '/')) {
                $options['path'] = $location;
            } else {
                $options['location'] = ltrim($location, '/');
            }
        }

        return new self(
            type: $type,
            credentials: $credentials,
            rateLimit: $rateLimit,
            options: $options,
        );
    }

    /**
     * Validate configuration based on storage type.
     */
    private function validate(): void
    {
        match ($this->type) {
            StorageType::GOOGLE_PHOTOS => $this->validateGooglePhotos(),
            StorageType::DROPBOX => $this->validateDropbox(),
            StorageType::S3 => $this->validateS3(),
            StorageType::LOCAL => $this->validateLocal(),
        };
    }

    private function validateGooglePhotos(): void
    {
        if (!isset($this->credentials['client_id']) || !isset($this->credentials['client_secret'])) {
            throw ConfigurationException::missingCredentials('Google Photos', 'client_id and client_secret');
        }
    }

    private function validateDropbox(): void
    {
        if (!isset($this->credentials['access_token'])) {
            throw ConfigurationException::missingCredentials('Dropbox', 'access_token');
        }
    }

    private function validateS3(): void
    {
        if (!isset($this->credentials['access_key']) || !isset($this->credentials['secret_key'])) {
            throw ConfigurationException::missingCredentials('S3', 'access_key and secret_key');
        }
        if (!isset($this->options['bucket'])) {
            throw ConfigurationException::missingOption('S3', 'bucket');
        }
    }

    private function validateLocal(): void
    {
        if (!isset($this->options['path'])) {
            throw ConfigurationException::missingOption('Local', 'path');
        }
    }
}
