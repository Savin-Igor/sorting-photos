<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Infrastructure\Storage\RateLimitConfiguration;

final class RateLimitConfigurationTest extends TestCase
{
    public function testCreateWithValidData(): void
    {
        $config = new RateLimitConfiguration(100, 60, 150);

        $this->assertEquals(100, $config->requests);
        $this->assertEquals(60, $config->perSeconds);
        $this->assertEquals(150, $config->burst);
    }

    public function testCreateWithoutBurst(): void
    {
        $config = new RateLimitConfiguration(100, 60);

        $this->assertEquals(100, $config->requests);
        $this->assertEquals(60, $config->perSeconds);
        $this->assertNull($config->burst);
    }

    public function testInvalidRequestsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rate limit requests must be greater than 0');
        new RateLimitConfiguration(0, 60);
    }

    public function testInvalidPerSecondsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rate limit perSeconds must be greater than 0');
        new RateLimitConfiguration(100, 0);
    }
}

