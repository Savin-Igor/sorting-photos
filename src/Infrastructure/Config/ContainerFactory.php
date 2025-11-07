<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Config;

use SortingPhotosByDate\Domain\Policies\DatePolicy;
use SortingPhotosByDate\Domain\Policies\DateTypePolicy;
use SortingPhotosByDate\Domain\Policies\OrganizerPolicy;
use SortingPhotosByDate\Domain\Policies\TypeDatePolicy;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
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
                new \Symfony\Component\DependencyInjection\Reference('service_container'),
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

        // If DSNs are not set, use default local storage
        if (null === $sourceDsn && null === $destinationDsn) {
            // Use default local storage configuration from storage.yaml
            return;
        }

        // Parse DSNs and configure adapters
        if (null !== $sourceDsn) {
            try {
                /** @var string $sourceDsn */
                $sourceConfig = \SortingPhotosByDate\Infrastructure\Storage\StorageConfiguration::fromDsn($sourceDsn);
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
                $this->configureStorageAdapter($container, $destinationConfig, 'destination');
            } catch (\Exception $e) {
                // Log error but don't fail - fall back to default
                error_log("Failed to configure destination storage from DSN: {$e->getMessage()}");
            }
        }
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

            // Set alias
            $container->setAlias($serviceId, $adapterServiceId);
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
        }
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
