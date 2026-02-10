<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage;

use Psr\Container\ContainerInterface;
use SortingPhotosByDate\Domain\Storage\StorageType;
use SortingPhotosByDate\Exceptions\ConfigurationException;
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
            throw ConfigurationException::adapterDoesNotImplement($config->type->value, 'StoragePort');
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
            throw ConfigurationException::adapterDoesNotImplement($config->type->value, 'DestinationStoragePort');
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
        throw ConfigurationException::localStorageAdapterDi();
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
        throw ConfigurationException::googlePhotosAdapterDi();
    }

    /**
     * Create Dropbox adapter.
     */
    private function createDropboxAdapter(): StoragePort&DestinationStoragePort
    {
        throw ConfigurationException::dropboxAdapterNotImplemented();
    }

    /**
     * Create S3 adapter.
     */
    private function createS3Adapter(): StoragePort&DestinationStoragePort
    {
        throw ConfigurationException::s3AdapterNotImplemented();
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
