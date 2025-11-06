<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain\ValueObjects;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\ValueObjects\FileHash;

final class FileHashTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir() . '/test_hash_' . uniqid() . '.txt';
        file_put_contents($this->testFile, 'test content');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testCreateWithValidHash(): void
    {
        $hash = new FileHash('a1b2c3d4e5f6');
        $this->assertEquals('a1b2c3d4e5f6', $hash->getHash());
    }

    public function testGetShortHash(): void
    {
        $hash = new FileHash('a1b2c3d4e5f6');
        $this->assertEquals('a1b2c3d4', $hash->getShortHash(8));
        $this->assertEquals('a1b2', $hash->getShortHash(4));
    }

    public function testGetShortHashDefaultLength(): void
    {
        $hash = new FileHash('a1b2c3d4e5f6');
        $this->assertEquals('a1b2c3d4', $hash->getShortHash());
    }

    public function testEquals(): void
    {
        $hash1 = new FileHash('a1b2c3d4e5f6');
        $hash2 = new FileHash('a1b2c3d4e5f6');
        $hash3 = new FileHash('f6e5d4c3b2a1');

        $this->assertTrue($hash1->equals($hash2));
        $this->assertFalse($hash1->equals($hash3));
    }

    public function testToString(): void
    {
        $hash = new FileHash('a1b2c3d4e5f6');
        $this->assertEquals('a1b2c3d4e5f6', (string) $hash);
    }

    public function testFromFile(): void
    {
        $hash = FileHash::fromFile($this->testFile);
        $expectedHash = hash_file('sha256', $this->testFile);
        $this->assertEquals($expectedHash, $hash->getHash());
    }

    public function testEmptyHashThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File hash cannot be empty');
        new FileHash('');
    }

    public function testInvalidHexHashThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File hash must be hexadecimal');
        new FileHash('invalid-hash!');
    }

    public function testFromFileNonExistentThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File does not exist');
        FileHash::fromFile('/non/existent/file.txt');
    }

    public function testShortHashInvalidLengthThrowsException(): void
    {
        $hash = new FileHash('a1b2c3d4e5f6');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hash length must be between 1 and 64');
        $hash->getShortHash(0);
    }

    public function testShortHashTooLongThrowsException(): void
    {
        $hash = new FileHash('a1b2c3d4e5f6');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hash length must be between 1 and 64');
        $hash->getShortHash(65);
    }
}

