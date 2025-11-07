<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage;

use Psr\Container\ContainerInterface;
use SortingPhotosByDate\Domain\Storage\StorageType;
use SortingPhotosByDate\Infrastructure\Storage\RateLimiting\RateLimiter;
use SortingPhotosByDate\Infrastructure\Storage\RateLimiting\RateLimitedDestinationStoragePort;
use SortingPhotosByDate\Infrastructure\Storage\RateLimiting\RateLimitedStoragePort;
use SortingPhotosByDate\Infrastructure\Storage\RateLimiting\TokenBucketRateLimiter;
use SortingPhotosByDate\Ports\Storage\DestinationStoragePort;
use SortingPhotosByDate\Ports\Storage\StoragePort;

/**
 * Factory for creating storage adapters from configuration.
 */
final readonly class StorageAdapterFactory
{
    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    /**
     * Create source storage port from configuration.
     *
     * @param StorageConfiguration $config Storage configuration
     *
     * @return StoragePort Storage port instance
     */
    public function createSource(StorageConfiguration $config): StoragePort
    {
        $adapter = $this->createAdapter($config);

        if (!$adapter instanceof StoragePort) {
            throw new \RuntimeException(\sprintf('Adapter for storage type %s does not implement StoragePort', $config->type->value));
        }

        // Apply rate limiting if configured
        if ($config->rateLimit instanceof RateLimitConfiguration) {
            $rateLimiter = $this->createRateLimiter($config->rateLimit);

            return new RateLimitedStoragePort($adapter, $rateLimiter);
        }

        return $adapter;
    }

    /**
     * Create destination storage port from configuration.
     *
     * @param StorageConfiguration $config Storage configuration
     *
     * @return DestinationStoragePort Destination storage port instance
     */
    public function createDestination(StorageConfiguration $config): DestinationStoragePort
    {
        $adapter = $this->createAdapter($config);

        if (!$adapter instanceof DestinationStoragePort) {
            throw new \RuntimeException(\sprintf('Adapter for storage type %s does not implement DestinationStoragePort', $config->type->value));
        }

        // Apply rate limiting if configured
        if ($config->rateLimit instanceof RateLimitConfiguration) {
            $rateLimiter = $this->createRateLimiter($config->rateLimit);

            return new RateLimitedDestinationStoragePort($adapter, $rateLimiter);
        }

        return $adapter;
    }

    /**
     * Create adapter instance based on storage type.
     *
     * @param StorageConfiguration $config Storage configuration
     *
     * @return StoragePort|DestinationStoragePort Adapter instance
     */
    private function createAdapter(StorageConfiguration $config): StoragePort|DestinationStoragePort
    {
        return match ($config->type) {
            StorageType::LOCAL => $this->createLocalAdapter(),
            StorageType::GOOGLE_PHOTOS => $this->createGooglePhotosAdapter(),
            StorageType::DROPBOX => $this->createDropboxAdapter(),
            StorageType::S3 => $this->createS3Adapter(),
        };
    }

    /**
     * Create local filesystem adapter.
     */
    private function createLocalAdapter(): StoragePort&DestinationStoragePort
    {
        // Try to get from container first (for DI integration)
        $serviceId = \SortingPhotosByDate\Adapters\Storage\Local\LocalStorageAdapter::class;
        if ($this->container->has($serviceId)) {
            $adapter = $this->container->get($serviceId);
            if ($adapter instanceof StoragePort && $adapter instanceof DestinationStoragePort) {
                return $adapter;
            }
        }
        // Fallback: create directly (will be replaced by DI later)
        throw new \RuntimeException('Local storage adapter must be configured via DI container');
    }

    /**
     * Create Google Photos adapter.
     */
    private function createGooglePhotosAdapter(): StoragePort&DestinationStoragePort
    {
        $serviceId = \SortingPhotosByDate\Adapters\Storage\GooglePhotos\GooglePhotosAdapter::class;
        if ($this->container->has($serviceId)) {
            $adapter = $this->container->get($serviceId);
            if ($adapter instanceof StoragePort && $adapter instanceof DestinationStoragePort) {
                return $adapter;
            }
        }
        throw new \RuntimeException('Google Photos adapter not yet implemented. Please configure via DI container.');
    }

    /**
     * Create Dropbox adapter.
     */
    private function createDropboxAdapter(): StoragePort&DestinationStoragePort
    {
        throw new \RuntimeException('Dropbox adapter not yet implemented');
    }

    /**
     * Create S3 adapter.
     */
    private function createS3Adapter(): StoragePort&DestinationStoragePort
    {
        throw new \RuntimeException('S3 adapter not yet implemented');
    }

    /**
     * Create rate limiter from configuration.
     */
    private function createRateLimiter(RateLimitConfiguration $config): RateLimiter
    {
        return new TokenBucketRateLimiter(
            requestsPerWindow: $config->requests,
            windowSeconds: $config->perSeconds,
            burst: $config->burst,
        );
    }
}
