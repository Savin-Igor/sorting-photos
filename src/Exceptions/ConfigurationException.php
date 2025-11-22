<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Exceptions;

final class ConfigurationException extends \RuntimeException
{
    private const string MISSING_ENV = '%s must be set in .env or environment variables';
    private const string INVALID_DSN = 'Invalid DSN format: %s';
    private const string MISSING_CREDENTIALS = '%s storage requires %s credentials';
    private const string MISSING_OPTION = '%s storage requires %s option';
    private const string INVALID_CREDENTIALS_FILE = 'Invalid credentials file: %s';
    private const string CREDENTIALS_NOT_FOUND = 'Credentials file not found: %s';
    private const string FAILED_READ_CREDENTIALS = 'Failed to read credentials file: %s';
    private const string INVALID_JSON_CREDENTIALS = 'Invalid JSON in credentials file: %s';
    private const string INVALID_CREDENTIALS_FORMAT = 'Invalid credentials file format: %s';
    private const string STORAGE_NOT_IMPLEMENTED = 'Storage type %s not yet fully implemented';
    private const string STORAGE_CONFIG_NOT_IMPLEMENTED = 'Storage type %s configuration not implemented';
    private const string ADAPTER_INTERFACE = 'Adapter for storage type %s does not implement %s';
    private const string INVALID_STORAGE_TYPE = 'Invalid storage type: %s';
    private const string REDIS_FACTORY_NOT_FOUND = 'Redis transport factory not found. Please install symfony/redis-messenger.';
    private const string LOCAL_STORAGE_ADAPTER_DI = 'Local storage adapter must be configured via DI container';
    private const string GOOGLE_PHOTOS_ADAPTER_DI = 'Google Photos adapter not yet implemented. Please configure via DI container.';
    private const string DROPBOX_ADAPTER_NOT_IMPLEMENTED = 'Dropbox adapter not yet implemented';
    private const string S3_ADAPTER_NOT_IMPLEMENTED = 'S3 adapter not yet implemented';
    private const string ADAPTER_NOT_IMPLEMENTED = 'Google Photos adapter %s() not yet implemented';

    public static function missingEnv(string $variable): self
    {
        return new self(\sprintf(self::MISSING_ENV, $variable));
    }

    public static function invalidDsn(string $dsn): self
    {
        return new self(\sprintf(self::INVALID_DSN, $dsn));
    }

    public static function missingCredentials(string $storage, string $fields): self
    {
        return new self(\sprintf(self::MISSING_CREDENTIALS, $storage, $fields));
    }

    public static function missingOption(string $storage, string $option): self
    {
        return new self(\sprintf(self::MISSING_OPTION, $storage, $option));
    }

    public static function invalidCredentialsFile(string $reason): self
    {
        return new self(\sprintf(self::INVALID_CREDENTIALS_FILE, $reason));
    }

    public static function credentialsNotFound(string $path): self
    {
        return new self(\sprintf(self::CREDENTIALS_NOT_FOUND, $path));
    }

    public static function failedReadCredentials(string $path): self
    {
        return new self(\sprintf(self::FAILED_READ_CREDENTIALS, $path));
    }

    public static function invalidJsonCredentials(string $path): self
    {
        return new self(\sprintf(self::INVALID_JSON_CREDENTIALS, $path));
    }

    public static function invalidCredentialsFormat(string $reason): self
    {
        return new self(\sprintf(self::INVALID_CREDENTIALS_FORMAT, $reason));
    }

    public static function storageNotImplemented(string $storage): self
    {
        return new self(\sprintf(self::STORAGE_NOT_IMPLEMENTED, $storage));
    }

    public static function storageConfigNotImplemented(string $storage): self
    {
        return new self(\sprintf(self::STORAGE_CONFIG_NOT_IMPLEMENTED, $storage));
    }

    public static function adapterDoesNotImplement(string $storage, string $interface): self
    {
        return new self(\sprintf(self::ADAPTER_INTERFACE, $storage, $interface));
    }

    public static function invalidStorageType(string $type): self
    {
        return new self(\sprintf(self::INVALID_STORAGE_TYPE, $type));
    }

    public static function redisFactoryNotFound(): self
    {
        return new self(self::REDIS_FACTORY_NOT_FOUND);
    }

    public static function localStorageAdapterDi(): self
    {
        return new self(self::LOCAL_STORAGE_ADAPTER_DI);
    }

    public static function googlePhotosAdapterDi(): self
    {
        return new self(self::GOOGLE_PHOTOS_ADAPTER_DI);
    }

    public static function dropboxAdapterNotImplemented(): self
    {
        return new self(self::DROPBOX_ADAPTER_NOT_IMPLEMENTED);
    }

    public static function s3AdapterNotImplemented(): self
    {
        return new self(self::S3_ADAPTER_NOT_IMPLEMENTED);
    }

    public static function adapterNotImplemented(string $methodName): self
    {
        return new self(\sprintf(self::ADAPTER_NOT_IMPLEMENTED, $methodName));
    }
}
