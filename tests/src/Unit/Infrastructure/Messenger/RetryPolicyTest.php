<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Infrastructure\Messenger;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Infrastructure\Messenger\RetryPolicy;
use SortingPhotosByDate\Ports\RetryPolicyInterface;

final class RetryPolicyTest extends TestCase
{
    public function testRetryPolicyImplementsInterface(): void
    {
        $policy = new RetryPolicy(
            maxRetries: 3,
            delay: 1000,
            multiplier: 2.0,
            maxDelay: 60000
        );

        $this->assertInstanceOf(RetryPolicyInterface::class, $policy);
    }

    public function testGetMaxRetries(): void
    {
        $policy = new RetryPolicy(
            maxRetries: 5,
            delay: 1000,
            multiplier: 2.0,
            maxDelay: 60000
        );

        $this->assertEquals(5, $policy->getMaxRetries());
    }

    public function testGetDelay(): void
    {
        $policy = new RetryPolicy(
            maxRetries: 3,
            delay: 2000,
            multiplier: 2.0,
            maxDelay: 60000
        );

        $this->assertEquals(2000, $policy->getDelay());
    }

    public function testGetMultiplier(): void
    {
        $policy = new RetryPolicy(
            maxRetries: 3,
            delay: 1000,
            multiplier: 3.0,
            maxDelay: 60000
        );

        $this->assertEquals(3.0, $policy->getMultiplier());
    }

    public function testGetMaxDelay(): void
    {
        $policy = new RetryPolicy(
            maxRetries: 3,
            delay: 1000,
            multiplier: 2.0,
            maxDelay: 120000
        );

        $this->assertEquals(120000, $policy->getMaxDelay());
    }

    public function testShouldRetryReturnsTrueForRetryableExceptions(): void
    {
        $policy = new RetryPolicy(
            maxRetries: 3,
            delay: 1000,
            multiplier: 2.0,
            maxDelay: 60000
        );

        $exception = new \RuntimeException('Temporary error');

        $this->assertTrue($policy->shouldRetry($exception));
    }

    public function testShouldRetryReturnsFalseForNonRetryableExceptions(): void
    {
        $policy = new RetryPolicy(
            maxRetries: 3,
            delay: 1000,
            multiplier: 2.0,
            maxDelay: 60000,
            nonRetryableExceptions: [\InvalidArgumentException::class]
        );

        $exception = new \InvalidArgumentException('Invalid argument');

        $this->assertFalse($policy->shouldRetry($exception));
    }

    public function testDefaultConfiguration(): void
    {
        $policy = new RetryPolicy();

        $this->assertEquals(3, $policy->getMaxRetries());
        $this->assertEquals(1000, $policy->getDelay());
        $this->assertEquals(2.0, $policy->getMultiplier());
        $this->assertEquals(60000, $policy->getMaxDelay());
    }
}

