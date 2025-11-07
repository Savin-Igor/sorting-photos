<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Infrastructure\Storage\RateLimiting;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Infrastructure\Storage\RateLimiting\TokenBucketRateLimiter;

final class TokenBucketRateLimiterTest extends TestCase
{
    public function testCreateWithValidConfig(): void
    {
        $limiter = new TokenBucketRateLimiter(100, 60);
        $this->assertEquals(0, $limiter->getCurrentCount());
    }

    public function testRecordRequest(): void
    {
        $limiter = new TokenBucketRateLimiter(100, 60);
        $limiter->recordRequest();
        $this->assertEquals(1, $limiter->getCurrentCount());
    }

    public function testWaitIfNeeded(): void
    {
        $limiter = new TokenBucketRateLimiter(2, 1); // 2 requests per second
        $start = microtime(true);

        // First request should not wait
        $limiter->waitIfNeeded();
        $time1 = microtime(true) - $start;

        // Second request should not wait
        $limiter->waitIfNeeded();
        $time2 = microtime(true) - $start;

        // Third request should wait
        $limiter->waitIfNeeded();
        $time3 = microtime(true) - $start;

        $this->assertLessThan(0.1, $time1); // First request should be fast
        $this->assertLessThan(0.1, $time2); // Second request should be fast
        $this->assertGreaterThan(0.9, $time3); // Third request should wait ~1 second
    }

    public function testReset(): void
    {
        $limiter = new TokenBucketRateLimiter(100, 60);
        $limiter->recordRequest();
        $limiter->recordRequest();
        $this->assertEquals(2, $limiter->getCurrentCount());

        $limiter->reset();
        $this->assertEquals(0, $limiter->getCurrentCount());
    }

    public function testInvalidRequestsPerWindowThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Requests per window must be greater than 0');
        new TokenBucketRateLimiter(0, 60);
    }

    public function testInvalidWindowSecondsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Window seconds must be greater than 0');
        new TokenBucketRateLimiter(100, 0);
    }

    public function testBurstLimit(): void
    {
        $limiter = new TokenBucketRateLimiter(2, 1, burst: 5);
        $start = microtime(true);

        // Should allow 5 requests without waiting
        for ($i = 0; $i < 5; ++$i) {
            $limiter->waitIfNeeded();
        }
        $time1 = microtime(true) - $start;

        // 6th request should wait
        $limiter->waitIfNeeded();
        $time2 = microtime(true) - $start;

        $this->assertLessThan(0.1, $time1); // First 5 requests should be fast
        $this->assertGreaterThan(0.9, $time2); // 6th request should wait
    }
}

