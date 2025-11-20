<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Config;

use SortingPhotosByDate\Application\Filter\FilterChain;
use SortingPhotosByDate\Domain\Policies\DatePolicy;
use SortingPhotosByDate\Domain\Policies\DateTypePolicy;
use SortingPhotosByDate\Domain\Policies\OrganizerPolicy;
use SortingPhotosByDate\Domain\Policies\TypeDatePolicy;
use SortingPhotosByDate\Infrastructure\Filter\FilterChainFactory;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Factory class for creating and configuring the application DI container.
 * Encapsulates all container setup logic for better maintainability.
 */
final readonly class ContainerFactory
{
    public function __construct(private string $projectDir)
    {
    }

    /**
     * Build and configure the application container.
     *
     * @return ContainerBuilder Configured and compiled container
     */
    public function build(): ContainerBuilder
    {
        $container = $this->createContainer();
        $this->setKernelParameters($container);
        $this->loadParameters($container);
        $this->loadServices($container);
        $this->configureOrganizerPolicy($container);
        $this->configureFilters($container);
        $this->compileContainer($container);

        return $container;
    }

    /**
     * Create a new container instance.
     */
    private function createContainer(): ContainerBuilder
    {
        return new ContainerBuilder();
    }

    /**
     * Set kernel-level parameters required before loading YAML files.
     */
    private function setKernelParameters(ContainerBuilder $container): void
    {
        $container->setParameter('kernel.project_dir', $this->projectDir);

        // Generate log file name with timestamp for each run
        $logFileName = date('Y-m-d_H-i-s').'.log';
        $container->setParameter('app.log_file', $logFileName);
    }

    /**
     * Load parameters from YAML and environment variables.
     */
    private function loadParameters(ContainerBuilder $container): void
    {
        $loader = $this->createYamlLoader($container);

        // Load parameters configuration first (sets defaults)
        $parametersFile = $this->projectDir.'/config/parameters.yaml';
        if (file_exists($parametersFile)) {
            $loader->load('parameters.yaml');
        }

        // Override parameters from environment variables (env vars take precedence)
        $parameters = ParameterResolver::resolveFromEnvironment();
        foreach ($parameters as $key => $value) {
            // Only override if env var is actually set (non-empty for required params)
            if (('app.source_directory' === $key || 'app.destination_directory' === $key) && '' !== $value) {
                /** @var string|int|bool $value */
                $container->setParameter($key, $value);
            } elseif ('app.source_directory' !== $key && 'app.destination_directory' !== $key) {
                // For optional params, always override
                /** @var string|int|bool $value */
                $container->setParameter($key, $value);
            }
        }
    }

    /**
     * Load services configuration from YAML files.
     */
    private function loadServices(ContainerBuilder $container): void
    {
        $loader = $this->createYamlLoader($container);

        // Load services configuration
        $loader->load('services.yaml');

        // Load flysystem configuration
        $flysystemFile = $this->projectDir.'/config/packages/flysystem.yaml';
        if (file_exists($flysystemFile)) {
            $loader->load('packages/flysystem.yaml');
        }

        // Load storage abstraction configuration
        $storageFile = $this->projectDir.'/config/packages/storage.yaml';
        if (file_exists($storageFile)) {
            $loader->load('packages/storage.yaml');
        }

        // Configure storage adapters based on DSN if provided
        $this->configureStorageAdapters($container);

        // Configure logging handlers based on parameters
        $this->configureLogging($container);

        // If async mode is enabled, override MessageBusInterface definition
        $asyncMode = getenv('ASYNC_MODE') ?: ($_ENV['ASYNC_MODE'] ?? 'false');
        $asyncMode = filter_var($asyncMode, FILTER_VALIDATE_BOOLEAN);

        if ($asyncMode && $container->hasDefinition(MessageBusInterface::class)) {
            $transportDsn = getenv('MESSENGER_TRANSPORT_DSN') ?: ($_ENV['MESSENGER_TRANSPORT_DSN'] ?? 'redis://redis:6379/messages');

            $messageRouting = [
                \SortingPhotosByDate\Domain\Event\FileDiscovered::class => 'async',
                \SortingPhotosByDate\Domain\Event\FileProcessed::class => 'async',
                \SortingPhotosByDate\Domain\Event\FileOrganized::class => 'async',
                \SortingPhotosByDate\Domain\Event\FileError::class => 'async',
                \SortingPhotosByDate\Domain\Event\FileSkipped::class => 'async',
                \SortingPhotosByDate\Application\Command\IngestFileCommand::class => 'async',
                \SortingPhotosByDate\Application\Command\OrganizeFileCommand::class => 'async',
            ];

            // Remove old definition completely and create new one with factory
            $container->removeDefinition(MessageBusInterface::class);

            $definition = new \Symfony\Component\DependencyInjection\Definition();
            $definition->setFactory([\SortingPhotosByDate\Infrastructure\Messenger\AsyncMessageBusFactory::class, 'create']);
            $definition->setArguments([
                new Reference('service_container'),
                $transportDsn,
                $messageRouting,
            ]);
            $definition->setPublic(true);
            $definition->setAutowired(false);
            $definition->setAutoconfigured(false);

            $container->setDefinition(MessageBusInterface::class, $definition);
        }
    }

    /**
     * Configure storage adapters based on DSN configuration.
     */
    private function configureStorageAdapters(ContainerBuilder $container): void
    {
        // Check if storage DSNs are configured
        $sourceDsn = getenv('SOURCE_STORAGE_DSN') ?: ($_ENV['SOURCE_STORAGE_DSN'] ?? null);
        $destinationDsn = getenv('DESTINATION_STORAGE_DSN') ?: ($_ENV['DESTINATION_STORAGE_DSN'] ?? null);

        // Get rate limit configuration from environment variables
        $rateLimitConfig = $this->getRateLimitFromEnvironment();

        // If DSNs are not set, use default local storage
        if (null === $sourceDsn && null === $destinationDsn) {
            // Apply rate limiting to default adapters if configured
            // Note: Rate limiting is applied to StoragePort/DestinationStoragePort,
            // but FilesystemPort continues to use LocalFilesystemAdapter directly
            // to avoid circular dependency (LocalStorageAdapter depends on FilesystemPort)
            if (null !== $rateLimitConfig) {
                $this->applyRateLimitingToDefaultAdapters($container, $rateLimitConfig);
            }

            return;
        }

        // Parse DSNs and configure adapters
        if (null !== $sourceDsn) {
            try {
                /** @var string $sourceDsn */
                $sourceConfig = \SortingPhotosByDate\Infrastructure\Storage\StorageConfiguration::fromDsn($sourceDsn);
                // Apply rate limit from environment if not specified in DSN
                if (null === $sourceConfig->rateLimit && null !== $rateLimitConfig) {
                    $sourceConfig = new \SortingPhotosByDate\Infrastructure\Storage\StorageConfiguration(
                        type: $sourceConfig->type,
                        credentials: $sourceConfig->credentials,
                        rateLimit: $rateLimitConfig,
                        options: $sourceConfig->options,
                    );
                }
                $this->configureStorageAdapter($container, $sourceConfig, 'source');
            } catch (\Exception $e) {
                // Log error but don't fail - fall back to default
                error_log("Failed to configure source storage from DSN: {$e->getMessage()}");
            }
        }

        if (null !== $destinationDsn) {
            try {
                /** @var string $destinationDsn */
                $destinationConfig = \SortingPhotosByDate\Infrastructure\Storage\StorageConfiguration::fromDsn($destinationDsn);
                // Apply rate limit from environment if not specified in DSN
                if (null === $destinationConfig->rateLimit && null !== $rateLimitConfig) {
                    $destinationConfig = new \SortingPhotosByDate\Infrastructure\Storage\StorageConfiguration(
                        type: $destinationConfig->type,
                        credentials: $destinationConfig->credentials,
                        rateLimit: $rateLimitConfig,
                        options: $destinationConfig->options,
                    );
                }
                $this->configureStorageAdapter($container, $destinationConfig, 'destination');
            } catch (\Exception $e) {
                // Log error but don't fail - fall back to default
                error_log("Failed to configure destination storage from DSN: {$e->getMessage()}");
            }
        }

        // Apply rate limiting to FilesystemPort if configured (for handlers that use FilesystemPort directly)
        if (null !== $rateLimitConfig) {
            $this->applyRateLimitingToFilesystemPort($container, $rateLimitConfig);
        }
    }

    /**
     * Apply rate limiting to FilesystemPort.
     */
    private function applyRateLimitingToFilesystemPort(
        ContainerBuilder $container,
        \SortingPhotosByDate\Infrastructure\Storage\RateLimitConfiguration $rateLimitConfig,
    ): void {
        $filesystemPortId = \SortingPhotosByDate\Ports\FilesystemPort::class;
        if (!$container->hasAlias($filesystemPortId)) {
            return;
        }

        // Create rate limiter service if not exists
        $rateLimiterId = 'SortingPhotosByDate\Infrastructure\Storage\RateLimiting\RateLimiter.default';
        if (!$container->hasDefinition($rateLimiterId)) {
            $rateLimiterDefinition = new \Symfony\Component\DependencyInjection\Definition(
                \SortingPhotosByDate\Infrastructure\Storage\RateLimiting\TokenBucketRateLimiter::class
            );
            $rateLimiterDefinition->setArguments([
                (int) $rateLimitConfig->requests,
                (int) $rateLimitConfig->perSeconds,
                null !== $rateLimitConfig->burst ? (int) $rateLimitConfig->burst : null,
            ]);
            $rateLimiterDefinition->setPublic(true);
            $container->setDefinition($rateLimiterId, $rateLimiterDefinition);
        }

        // Wrap FilesystemPort with rate limiter
        $originalFilesystemId = (string) $container->getAlias($filesystemPortId);
        $this->wrapFilesystemPortWithRateLimiter($container, $originalFilesystemId, $filesystemPortId, $rateLimiterId);
    }

    /**
     * Get rate limit configuration from environment variables.
     */
    private function getRateLimitFromEnvironment(): ?\SortingPhotosByDate\Infrastructure\Storage\RateLimitConfiguration
    {
        $requests = getenv('RATE_LIMIT_REQUESTS') ?: ($_ENV['RATE_LIMIT_REQUESTS'] ?? null);
        $perSeconds = getenv('RATE_LIMIT_PER_SECONDS') ?: ($_ENV['RATE_LIMIT_PER_SECONDS'] ?? null);

        if (null === $requests || null === $perSeconds) {
            return null;
        }

        if (!is_numeric($requests) || !is_numeric($perSeconds)) {
            error_log('Invalid rate limit configuration: requests and perSeconds must be numeric');

            return null;
        }

        $burst = getenv('RATE_LIMIT_BURST') ?: ($_ENV['RATE_LIMIT_BURST'] ?? null);

        try {
            return new \SortingPhotosByDate\Infrastructure\Storage\RateLimitConfiguration(
                requests: (int) $requests,
                perSeconds: (int) $perSeconds,
                burst: null !== $burst && is_numeric($burst) ? (int) $burst : null,
            );
        } catch (\Exception $e) {
            error_log("Invalid rate limit configuration: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Apply rate limiting to default storage adapters.
     */
    private function applyRateLimitingToDefaultAdapters(
        ContainerBuilder $container,
        \SortingPhotosByDate\Infrastructure\Storage\RateLimitConfiguration $rateLimitConfig,
    ): void {
        // Create rate limiter service
        $rateLimiterId = 'SortingPhotosByDate\Infrastructure\Storage\RateLimiting\RateLimiter.default';
        if (!$container->hasDefinition($rateLimiterId)) {
            $rateLimiterDefinition = new \Symfony\Component\DependencyInjection\Definition(
                \SortingPhotosByDate\Infrastructure\Storage\RateLimiting\TokenBucketRateLimiter::class
            );
            $rateLimiterDefinition->setArguments([
                (int) $rateLimitConfig->requests,
                (int) $rateLimitConfig->perSeconds,
                null !== $rateLimitConfig->burst ? (int) $rateLimitConfig->burst : null,
            ]);
            $rateLimiterDefinition->setPublic(true);
            $container->setDefinition($rateLimiterId, $rateLimiterDefinition);
        }

        // Wrap FilesystemPort with rate limiter (used by handlers)
        $filesystemPortId = \SortingPhotosByDate\Ports\FilesystemPort::class;
        if ($container->hasAlias($filesystemPortId)) {
            $originalFilesystemId = (string) $container->getAlias($filesystemPortId);
            $this->wrapFilesystemPortWithRateLimiter($container, $originalFilesystemId, $filesystemPortId, $rateLimiterId);
        }

        // Wrap source storage port with rate limiter
        $sourcePortId = \SortingPhotosByDate\Ports\Storage\StoragePort::class;
        if ($container->hasAlias($sourcePortId)) {
            $sourceAdapterId = (string) $container->getAlias($sourcePortId);
            $this->wrapAdapterWithRateLimiter($container, $sourceAdapterId, $sourcePortId, $rateLimiterId, true);
        }

        // Wrap destination storage port with rate limiter
        $destinationPortId = \SortingPhotosByDate\Ports\Storage\DestinationStoragePort::class;
        if ($container->hasAlias($destinationPortId)) {
            $destinationAdapterId = (string) $container->getAlias($destinationPortId);
            $this->wrapAdapterWithRateLimiter($container, $destinationAdapterId, $destinationPortId, $rateLimiterId, false);
        }
    }

    /**
     * Wrap FilesystemPort with rate limiter decorator.
     */
    private function wrapFilesystemPortWithRateLimiter(
        ContainerBuilder $container,
        string $originalFilesystemId,
        string $filesystemPortId,
        string $rateLimiterId,
    ): void {
        $wrapperId = $filesystemPortId.'.rate_limited';
        if ($container->hasDefinition($wrapperId)) {
            return; // Already wrapped
        }

        $wrapperDefinition = new \Symfony\Component\DependencyInjection\Definition(
            \SortingPhotosByDate\Infrastructure\Storage\RateLimiting\RateLimitedFilesystemPort::class
        );
        $wrapperDefinition->setArguments([
            new Reference($originalFilesystemId),
            new Reference($rateLimiterId),
        ]);
        $wrapperDefinition->setPublic(true);

        $container->setDefinition($wrapperId, $wrapperDefinition);
        $alias = $container->setAlias($filesystemPortId, $wrapperId);
        $alias->setPublic(true);
    }

    /**
     * Wrap adapter with rate limiter decorator.
     */
    private function wrapAdapterWithRateLimiter(
        ContainerBuilder $container,
        string $adapterId,
        string $portId,
        string $rateLimiterId,
        bool $isSource,
    ): void {
        $wrapperId = $portId.'.rate_limited';
        if ($container->hasDefinition($wrapperId)) {
            return; // Already wrapped
        }

        $wrapperClass = $isSource
            ? \SortingPhotosByDate\Infrastructure\Storage\RateLimiting\RateLimitedStoragePort::class
            : \SortingPhotosByDate\Infrastructure\Storage\RateLimiting\RateLimitedDestinationStoragePort::class;

        $wrapperDefinition = new \Symfony\Component\DependencyInjection\Definition($wrapperClass);
        $wrapperDefinition->setArguments([
            new Reference($adapterId),
            new Reference($rateLimiterId),
        ]);
        $wrapperDefinition->setPublic(true);

        $container->setDefinition($wrapperId, $wrapperDefinition);
        $alias = $container->setAlias($portId, $wrapperId);
        $alias->setPublic(true);
    }

    /**
     * Configure a single storage adapter.
     */
    private function configureStorageAdapter(
        ContainerBuilder $container,
        \SortingPhotosByDate\Infrastructure\Storage\StorageConfiguration $config,
        string $type,
    ): void {
        $adapterClass = match ($config->type) {
            \SortingPhotosByDate\Domain\Storage\StorageType::LOCAL => \SortingPhotosByDate\Adapters\Storage\Local\LocalStorageAdapter::class,
            \SortingPhotosByDate\Domain\Storage\StorageType::GOOGLE_PHOTOS => \SortingPhotosByDate\Adapters\Storage\GooglePhotos\GooglePhotosAdapter::class,
            default => throw new \RuntimeException("Storage type {$config->type->value} not yet fully implemented"),
        };

        $serviceId = match ($type) {
            'source' => \SortingPhotosByDate\Ports\Storage\StoragePort::class,
            'destination' => \SortingPhotosByDate\Ports\Storage\DestinationStoragePort::class,
            default => throw new \InvalidArgumentException("Invalid storage type: {$type}"),
        };

        // Configure adapter service if it's not local (local is already configured)
        if (\SortingPhotosByDate\Domain\Storage\StorageType::LOCAL !== $config->type) {
            $adapterServiceId = $adapterClass;
            if (!$container->hasDefinition($adapterServiceId)) {
                $adapterDefinition = new \Symfony\Component\DependencyInjection\Definition($adapterClass);
                $adapterDefinition->setPublic(true);
                $adapterDefinition->setAutowired(false);
                $adapterDefinition->setAutoconfigured(false);

                // Set arguments based on storage type
                match ($config->type) {
                    \SortingPhotosByDate\Domain\Storage\StorageType::GOOGLE_PHOTOS => $adapterDefinition->setArguments([
                        $config->credentials,
                        $config->options,
                    ]),
                    default => throw new \RuntimeException("Storage type {$config->type->value} configuration not implemented"),
                };

                $container->setDefinition($adapterServiceId, $adapterDefinition);
            }

            // Apply rate limiting if configured
            if (null !== $config->rateLimit) {
                $rateLimiterId = $serviceId.'.rate_limiter';
                if (!$container->hasDefinition($rateLimiterId)) {
                    $rateLimiterDefinition = new \Symfony\Component\DependencyInjection\Definition(
                        \SortingPhotosByDate\Infrastructure\Storage\RateLimiting\TokenBucketRateLimiter::class
                    );
                    $rateLimiterDefinition->setArguments([
                        $config->rateLimit->requests,
                        $config->rateLimit->perSeconds,
                        $config->rateLimit->burst,
                    ]);
                    $rateLimiterDefinition->setPublic(true);
                    $container->setDefinition($rateLimiterId, $rateLimiterDefinition);
                }

                $this->wrapAdapterWithRateLimiter($container, $adapterServiceId, $serviceId, $rateLimiterId, 'source' === $type);
            } else {
                // Set alias without rate limiting
                $container->setAlias($serviceId, $adapterServiceId);
            }
        } else {
            // For local storage, update basePath if needed
            if (isset($config->options['location'])) {
                $localAdapterId = \SortingPhotosByDate\Adapters\Storage\Local\LocalStorageAdapter::class;
                if ($container->hasDefinition($localAdapterId)) {
                    $definition = $container->getDefinition($localAdapterId);
                    $arguments = $definition->getArguments();
                    $arguments['$basePath'] = $config->options['location'];
                    $definition->setArguments($arguments);
                }
            }

            // Apply rate limiting to local adapter if configured
            if (null !== $config->rateLimit) {
                $rateLimiterId = $serviceId.'.rate_limiter';
                if (!$container->hasDefinition($rateLimiterId)) {
                    $rateLimiterDefinition = new \Symfony\Component\DependencyInjection\Definition(
                        \SortingPhotosByDate\Infrastructure\Storage\RateLimiting\TokenBucketRateLimiter::class
                    );
                    $rateLimiterDefinition->setArguments([
                        $config->rateLimit->requests,
                        $config->rateLimit->perSeconds,
                        $config->rateLimit->burst,
                    ]);
                    $rateLimiterDefinition->setPublic(true);
                    $container->setDefinition($rateLimiterId, $rateLimiterDefinition);
                }

                // Get the actual adapter ID from alias
                $adapterId = 'source' === $type
                    ? 'SortingPhotosByDate\Adapters\Storage\Local\LocalStorageAdapter.source'
                    : \SortingPhotosByDate\Adapters\Storage\Local\LocalStorageAdapter::class;

                $this->wrapAdapterWithRateLimiter($container, $adapterId, $serviceId, $rateLimiterId, 'source' === $type);
            }
        }
    }

    /**
     * Configure filters based on parameters.
     */
    private function configureFilters(ContainerBuilder $container): void
    {
        // Check if filters are enabled
        $filtersEnabled = $container->hasParameter('app.filters.enabled')
            ? (bool) $container->getParameter('app.filters.enabled')
            : false;

        if (!$filtersEnabled) {
            return; // Filters disabled, FilterChain will be null
        }

        // Build filter configuration from parameters
        $filterConfig = [
            'enabled' => true,
            'file_type' => [
                'enabled' => $container->hasParameter('app.filters.file_type.enabled')
                    ? (bool) $container->getParameter('app.filters.file_type.enabled')
                    : false,
                'allowed' => $container->hasParameter('app.filters.file_type.allowed')
                    ? $container->getParameter('app.filters.file_type.allowed')
                    : null,
                'denied' => $container->hasParameter('app.filters.file_type.denied')
                    ? $container->getParameter('app.filters.file_type.denied')
                    : null,
            ],
            'mime_type' => [
                'enabled' => $container->hasParameter('app.filters.mime_type.enabled')
                    ? (bool) $container->getParameter('app.filters.mime_type.enabled')
                    : false,
                'allowed' => $container->hasParameter('app.filters.mime_type.allowed')
                    ? $container->getParameter('app.filters.mime_type.allowed')
                    : null,
                'denied' => $container->hasParameter('app.filters.mime_type.denied')
                    ? $container->getParameter('app.filters.mime_type.denied')
                    : null,
                'allowed_patterns' => $container->hasParameter('app.filters.mime_type.allowed_patterns')
                    ? $container->getParameter('app.filters.mime_type.allowed_patterns')
                    : null,
                'denied_patterns' => $container->hasParameter('app.filters.mime_type.denied_patterns')
                    ? $container->getParameter('app.filters.mime_type.denied_patterns')
                    : null,
            ],
            'file_size' => [
                'enabled' => $container->hasParameter('app.filters.file_size.enabled')
                    ? (bool) $container->getParameter('app.filters.file_size.enabled')
                    : false,
                'min_bytes' => $container->hasParameter('app.filters.file_size.min_bytes')
                    ? $container->getParameter('app.filters.file_size.min_bytes')
                    : null,
                'max_bytes' => $container->hasParameter('app.filters.file_size.max_bytes')
                    ? $container->getParameter('app.filters.file_size.max_bytes')
                    : null,
            ],
            'filename_regex' => [
                'enabled' => $container->hasParameter('app.filters.filename_regex.enabled')
                    ? (bool) $container->getParameter('app.filters.filename_regex.enabled')
                    : false,
                'patterns' => $container->hasParameter('app.filters.filename_regex.patterns')
                    ? $container->getParameter('app.filters.filename_regex.patterns')
                    : [],
            ],
            'date' => [
                'enabled' => $container->hasParameter('app.filters.date.enabled')
                    ? (bool) $container->getParameter('app.filters.date.enabled')
                    : false,
                'from_date' => $container->hasParameter('app.filters.date.from_date')
                    ? $container->getParameter('app.filters.date.from_date')
                    : null,
                'to_date' => $container->hasParameter('app.filters.date.to_date')
                    ? $container->getParameter('app.filters.date.to_date')
                    : null,
                'check_filename' => $container->hasParameter('app.filters.date.check_filename')
                    ? (bool) $container->getParameter('app.filters.date.check_filename')
                    : true,
                'check_metadata' => $container->hasParameter('app.filters.date.check_metadata')
                    ? (bool) $container->getParameter('app.filters.date.check_metadata')
                    : true,
            ],
        ];

        // Create FilterChainFactory definition if not exists
        if (!$container->hasDefinition(FilterChainFactory::class)) {
            $factoryDefinition = $container->register(FilterChainFactory::class, FilterChainFactory::class);
            $factoryDefinition->setAutowired(true);
        }

        // Create FilterChain using factory
        // We'll use a factory method that will be called after container compilation
        // For now, we'll set it up to be created lazily
        $filterChainDefinition = $container->register(FilterChain::class, FilterChain::class);
        $filterChainDefinition->setFactory([new Reference(FilterChainFactory::class), 'create']);
        $filterChainDefinition->setArguments([$filterConfig]);
        $filterChainDefinition->setPublic(false);
    }

    /**
     * Configure organizer policy based on parameter.
     */
    private function configureOrganizerPolicy(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('app.organizer_policy')) {
            return;
        }

        /** @var string $organizerPolicy */
        $organizerPolicy = $container->getParameter('app.organizer_policy');
        $policyClass = $this->resolvePolicyClass($organizerPolicy);

        // Override policy alias before compiling
        $container->setAlias(OrganizerPolicy::class, $policyClass);
    }

    /**
     * Resolve policy class name from policy string.
     *
     * @param string $policy Policy name (date-type, date, type-date)
     *
     * @return string Fully qualified class name
     */
    private function resolvePolicyClass(string $policy): string
    {
        return match ($policy) {
            'date-type' => DateTypePolicy::class,
            'date' => DatePolicy::class,
            'type-date' => TypeDatePolicy::class,
            default => DateTypePolicy::class,
        };
    }

    /**
     * Configure logging handlers based on parameters.
     */
    private function configureLogging(ContainerBuilder $container): void
    {
        // Get logging parameters with defaults
        $fileEnabled = $container->hasParameter('app.logging.file_enabled')
            ? (bool) $container->getParameter('app.logging.file_enabled')
            : true;
        $fileLevelParam = $container->hasParameter('app.logging.file_level')
            ? $container->getParameter('app.logging.file_level')
            : 'DEBUG';
        $fileLevel = strtoupper(is_string($fileLevelParam) ? $fileLevelParam : 'DEBUG');
        $consoleLevelParam = $container->hasParameter('app.logging.console_level')
            ? $container->getParameter('app.logging.console_level')
            : 'INFO';
        $consoleLevel = strtoupper(is_string($consoleLevelParam) ? $consoleLevelParam : 'INFO');

        // Convert level strings to Monolog constants
        $levelMap = [
            'DEBUG' => \Monolog\Logger::DEBUG,
            'INFO' => \Monolog\Logger::INFO,
            'WARNING' => \Monolog\Logger::WARNING,
            'ERROR' => \Monolog\Logger::ERROR,
            'CRITICAL' => \Monolog\Logger::CRITICAL,
            'ALERT' => \Monolog\Logger::ALERT,
            'EMERGENCY' => \Monolog\Logger::EMERGENCY,
        ];

        $consoleLevelInt = $levelMap[$consoleLevel] ?? \Monolog\Logger::INFO;
        $fileLevelInt = $levelMap[$fileLevel] ?? \Monolog\Logger::DEBUG;

        // Update console handler level
        if ($container->hasDefinition('monolog.handler.console')) {
            $consoleHandler = $container->getDefinition('monolog.handler.console');
            $arguments = $consoleHandler->getArguments();
            $arguments['$level'] = $consoleLevelInt;
            $consoleHandler->setArguments($arguments);
        }

        // Configure file handler
        if ($container->hasDefinition('monolog.handler.file')) {
            if (!$fileEnabled || 'OFF' === $fileLevel) {
                // File handler will be excluded from logger handlers list
                // but we keep the definition in case it's needed elsewhere
            } else {
                // Update file handler level
                $fileHandler = $container->getDefinition('monolog.handler.file');
                $arguments = $fileHandler->getArguments();
                $arguments['$level'] = $fileLevelInt;
                $fileHandler->setArguments($arguments);
            }
        }

        // Update Logger to only include enabled handlers
        if ($container->hasDefinition(\Monolog\Logger::class)) {
            $loggerDef = $container->getDefinition(\Monolog\Logger::class);
            $handlers = [];

            // Always include console handler
            if ($container->hasDefinition('monolog.handler.console')) {
                $handlers[] = new Reference('monolog.handler.console');
            }

            // Include file handler only if enabled
            if ($fileEnabled && 'OFF' !== $fileLevel && $container->hasDefinition('monolog.handler.file')) {
                $handlers[] = new Reference('monolog.handler.file');
            }

            $loggerDef->setArguments([
                'file-sorter',
                $handlers,
            ]);
        }
    }

    /**
     * Compile the container.
     */
    private function compileContainer(ContainerBuilder $container): void
    {
        $container->compile();
    }

    /**
     * Create YAML file loader for the container.
     */
    private function createYamlLoader(ContainerBuilder $container): YamlFileLoader
    {
        return new YamlFileLoader($container, new FileLocator($this->projectDir.'/config'));
    }

    /**
     * Validate that required parameters are set.
     *
     * @throws \RuntimeException If required parameters are missing
     */
    public function validateRequiredParameters(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('app.source_directory') || !$container->hasParameter('app.destination_directory')) {
            throw new \RuntimeException('SOURCE_DIRECTORY and DESTINATION_DIRECTORY must be set in .env file or environment variables');
        }

        /** @var string $sourceDirectory */
        $sourceDirectory = $container->getParameter('app.source_directory');
        /** @var string $destinationDirectory */
        $destinationDirectory = $container->getParameter('app.destination_directory');

        if ('' === $sourceDirectory || '' === $destinationDirectory) {
            throw new \RuntimeException('SOURCE_DIRECTORY and DESTINATION_DIRECTORY must be set in .env file or environment variables');
        }
    }
}
