<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Infrastructure\Filesystem;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter as FlysystemLocalAdapter;
use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Adapters\Filesystem\LocalFilesystemAdapter;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Filesystem\MetadataPreservingCopier;

final class MetadataPreservingCopierTest extends TestCase
{
    private MetadataPreservingCopier $copier;
    private LocalFilesystemAdapter $filesystemAdapter;

    protected function setUp(): void
    {
        // Use real filesystem adapter for integration-style tests
        $tempDir = sys_get_temp_dir();
        $flysystemAdapter = new FlysystemLocalAdapter($tempDir);
        $flysystem = new Filesystem($flysystemAdapter);

        // Create a temporary MetadataPreservingCopier instance for the adapter
        $tempCopier = new MetadataPreservingCopier(
            $this->createMock(\SortingPhotosByDate\Ports\FilesystemPort::class)
        );

        $this->filesystemAdapter = new LocalFilesystemAdapter(
            $tempCopier,
            $flysystem,
            0755
        );

        // Create copier with real filesystem adapter
        $this->copier = new MetadataPreservingCopier($this->filesystemAdapter);
    }

    public function testCopyPreservesPermissions(): void
    {
        // Create temporary source file with specific permissions
        $tempDir = sys_get_temp_dir();
        $sourceFile = $tempDir.'/test_source_'.uniqid().'.txt';
        $destinationFile = $tempDir.'/test_dest_'.uniqid().'.txt';

        file_put_contents($sourceFile, 'test content');
        chmod($sourceFile, 0644);

        // Use relative paths for Flysystem (relative to tempDir)
        $sourcePath = new FilePath('test_source_'.basename($sourceFile));
        $destinationPath = new FilePath('test_dest_'.basename($destinationFile));

        $result = $this->copier->copy($sourcePath, $destinationPath);

        $this->assertTrue($result);
        $this->assertFileExists($tempDir.'/test_dest_'.basename($destinationFile));

        $sourcePerms = fileperms($sourceFile) & 0777;
        $destPerms = fileperms($tempDir.'/test_dest_'.basename($destinationFile)) & 0777;
        $this->assertEquals($sourcePerms, $destPerms);

        // Cleanup
        @unlink($sourceFile);
        @unlink($tempDir.'/test_dest_'.basename($destinationFile));
    }

    public function testCopyPreservesTimestamps(): void
    {
        // Create temporary source file
        $sourceFile = sys_get_temp_dir().'/test_source_'.uniqid().'.txt';
        $destinationFile = sys_get_temp_dir().'/test_dest_'.uniqid().'.txt';

        file_put_contents($sourceFile, 'test content');
        $expectedMtime = 1234567890;
        $expectedAtime = 1234567891;
        touch($sourceFile, $expectedMtime, $expectedAtime);

        $sourcePath = new FilePath($sourceFile);
        $destinationPath = new FilePath($destinationFile);

        $result = $this->copier->copy($sourcePath, $destinationPath);

        $this->assertTrue($result);
        $this->assertFileExists($destinationFile);

        $destMtime = filemtime($destinationFile);
        $destAtime = fileatime($destinationFile);

        $this->assertEquals($expectedMtime, $destMtime);
        $this->assertEquals($expectedAtime, $destAtime);

        // Cleanup
        @unlink($sourceFile);
        @unlink($destinationFile);
    }

    public function testCopyPreservesExtendedAttributes(): void
    {
        // Create temporary source file
        $sourceFile = sys_get_temp_dir().'/test_source_'.uniqid().'.txt';
        $destinationFile = sys_get_temp_dir().'/test_dest_'.uniqid().'.txt';

        file_put_contents($sourceFile, 'test content');

        $sourcePath = new FilePath($sourceFile);
        $destinationPath = new FilePath($destinationFile);

        // Set extended attribute if supported
        if (function_exists('xattr_set')) {
            xattr_set($sourceFile, 'user.test', 'test_value');
        }

        $result = $this->copier->copy($sourcePath, $destinationPath);

        $this->assertTrue($result);
        $this->assertFileExists($destinationFile);

        // Verify extended attributes if supported
        if (function_exists('xattr_get')) {
            $sourceAttr = xattr_get($sourceFile, 'user.test');
            $destAttr = xattr_get($destinationFile, 'user.test');
            $this->assertEquals($sourceAttr, $destAttr);
        }

        // Cleanup
        @unlink($sourceFile);
        @unlink($destinationFile);
    }

    public function testCopyCreatesDestinationDirectory(): void
    {
        // Create temporary source file
        $sourceFile = sys_get_temp_dir().'/test_source_'.uniqid().'.txt';
        $destDir = sys_get_temp_dir().'/test_dir_'.uniqid();
        $destinationFile = $destDir.'/test_dest.txt';

        file_put_contents($sourceFile, 'test content');

        $sourcePath = new FilePath($sourceFile);
        $destinationPath = new FilePath($destinationFile);

        $result = $this->copier->copy($sourcePath, $destinationPath);

        $this->assertTrue($result);
        $this->assertFileExists($destinationFile);
        $this->assertDirectoryExists($destDir);

        // Cleanup
        @unlink($sourceFile);
        @unlink($destinationFile);
        @rmdir($destDir);
    }

    public function testCopyThrowsExceptionWhenSourceDoesNotExist(): void
    {
        $sourcePath = new FilePath('/nonexistent/file.txt');
        $destinationPath = new FilePath('/tmp/dest.txt');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Source file does not exist');

        $this->copier->copy($sourcePath, $destinationPath);
    }

    public function testCopyVerifiesFileIntegrity(): void
    {
        // Create temporary source file
        $sourceFile = sys_get_temp_dir().'/test_source_'.uniqid().'.txt';
        $destinationFile = sys_get_temp_dir().'/test_dest_'.uniqid().'.txt';

        $content = 'test content for integrity check';
        file_put_contents($sourceFile, $content);

        $sourcePath = new FilePath($sourceFile);
        $destinationPath = new FilePath($destinationFile);

        $result = $this->copier->copy($sourcePath, $destinationPath);

        $this->assertTrue($result);
        $this->assertFileExists($destinationFile);

        // Verify content matches
        $sourceContent = file_get_contents($sourceFile);
        $destContent = file_get_contents($destinationFile);
        $this->assertEquals($sourceContent, $destContent);

        // Verify hash matches
        $sourceHash = hash_file('sha256', $sourceFile);
        $destHash = hash_file('sha256', $destinationFile);
        $this->assertEquals($sourceHash, $destHash);

        // Cleanup
        @unlink($sourceFile);
        @unlink($destinationFile);
    }
}
