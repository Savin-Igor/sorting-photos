<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\Storage\StorageType;
use SortingPhotosByDate\Infrastructure\Storage\RateLimitConfiguration;
use SortingPhotosByDate\Infrastructure\Storage\StorageConfiguration;

final class StorageConfigurationTest extends TestCase
{
    public function testCreateWithValidData(): void
    {
        $config = new StorageConfiguration(
            type: StorageType::LOCAL,
            credentials: ['key' => 'value'],
            rateLimit: new RateLimitConfiguration(100, 60),
            options: ['path' => '/tmp']
        );

        $this->assertEquals(StorageType::LOCAL, $config->type);
        $this->assertEquals(['key' => 'value'], $config->credentials);
        $this->assertNotNull($config->rateLimit);
        $this->assertEquals(['path' => '/tmp'], $config->options);
    }

    public function testFromDsnLocal(): void
    {
        $config = StorageConfiguration::fromDsn('local:///path/to/dir');

        $this->assertEquals(StorageType::LOCAL, $config->type);
        $this->assertEquals(['path' => '/path/to/dir'], $config->options);
    }

    public function testFromDsnGooglePhotos(): void
    {
        $config = StorageConfiguration::fromDsn('google-photos://album-id?client_id=xxx&client_secret=yyy');

        $this->assertEquals(StorageType::GOOGLE_PHOTOS, $config->type);
        $this->assertEquals(['client_id' => 'xxx', 'client_secret' => 'yyy'], $config->credentials);
        $this->assertEquals(['location' => 'album-id'], $config->options);
    }

    public function testFromDsnWithRateLimit(): void
    {
        $config = StorageConfiguration::fromDsn('google-photos://album-id?client_id=xxx&client_secret=yyy&rate_limit_requests=100&rate_limit_per_seconds=60');

        $this->assertNotNull($config->rateLimit);
        $this->assertEquals(100, $config->rateLimit->requests);
        $this->assertEquals(60, $config->rateLimit->perSeconds);
    }

    public function testFromDsnInvalidThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StorageConfiguration::fromDsn('invalid-dsn');
    }

    public function testValidateGooglePhotos(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Google Photos storage requires client_id and client_secret credentials');
        new StorageConfiguration(
            type: StorageType::GOOGLE_PHOTOS,
            credentials: []
        );
    }

    public function testValidateLocal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Local storage requires path option');
        new StorageConfiguration(
            type: StorageType::LOCAL,
            options: []
        );
    }

    public function testValidateDropbox(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dropbox storage requires access_token credential');
        new StorageConfiguration(
            type: StorageType::DROPBOX,
            credentials: []
        );
    }

    public function testValidateS3(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('S3 storage requires access_key and secret_key credentials');
        new StorageConfiguration(
            type: StorageType::S3,
            credentials: []
        );
    }

    public function testValidateS3MissingBucket(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('S3 storage requires bucket option');
        new StorageConfiguration(
            type: StorageType::S3,
            credentials: ['access_key' => 'key', 'secret_key' => 'secret'],
            options: []
        );
    }
}

