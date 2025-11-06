<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Command\ScanFilesCommand;
use SortingPhotosByDate\Domain\Event\FileDiscovered;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\FilesystemPort;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\ScannerPort;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ScanFilesCommandTest extends TestCase
{
    private ScannerPort $scanner;
    private MessageBusInterface $messageBus;
    private FilesystemPort $filesystem;
    private LoggerPort $logger;
    private ScanFilesCommand $command;

    protected function setUp(): void
    {
        $this->scanner = $this->createMock(ScannerPort::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->filesystem = $this->createMock(FilesystemPort::class);
        $this->logger = $this->createMock(LoggerPort::class);

        $this->command = new ScanFilesCommand(
            $this->scanner,
            $this->messageBus,
            $this->filesystem,
            $this->logger
        );
    }

    public function testExecuteScansDirectoryAndDispatchesEvents(): void
    {
        $sourceDir = '/test/source';
        $filePath1 = new FilePath('/test/source/file1.jpg');
        $filePath2 = new FilePath('/test/source/file2.mp4');

        $this->scanner
            ->expects($this->once())
            ->method('scan')
            ->with($sourceDir)
            ->willReturnCallback(function () use ($filePath1, $filePath2) {
                yield $filePath1;
                yield $filePath2;
            });

        $this->filesystem
            ->expects($this->exactly(2))
            ->method('exists')
            ->willReturn(true);

        $this->filesystem
            ->expects($this->exactly(2))
            ->method('getSize')
            ->willReturnOnConsecutiveCalls(1024, 2048);

        $this->messageBus
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->with($this->isInstanceOf(FileDiscovered::class))
            ->willReturnCallback(fn($event): Envelope => new Envelope($event));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        $commandTester = new \Symfony\Component\Console\Tester\CommandTester($this->command);
        $commandTester->execute([
            'source-directory' => $sourceDir,
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertTrue(
            str_contains($output, 'Scan completed') || str_contains($output, 'files found'),
            'Output should contain scan completion message'
        );
    }

    public function testExecuteWithDryRunMode(): void
    {
        $sourceDir = '/test/source';
        $filePath = new FilePath('/test/source/file.jpg');

        $this->scanner
            ->expects($this->once())
            ->method('scan')
            ->with($sourceDir)
            ->willReturnCallback(function () use ($filePath) {
                yield $filePath;
            });

        $this->filesystem
            ->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $this->filesystem
            ->expects($this->once())
            ->method('getSize')
            ->willReturn(1024);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(FileDiscovered::class))
            ->willReturnCallback(fn($event): Envelope => new Envelope($event));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->logicalOr(
                $this->stringContains('Dry-run'),
                $this->stringContains('Starting file scan'),
                $this->stringContains('File scan completed')
            ));

        $commandTester = new \Symfony\Component\Console\Tester\CommandTester($this->command);
        $commandTester->execute([
            'source-directory' => $sourceDir,
            '--dry-run' => true,
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertTrue(
            str_contains($output, 'Dry-run') || str_contains($output, 'DRY-RUN') || str_contains($output, 'Scan completed'),
            'Output should contain dry-run or completion message'
        );
    }

    public function testExecuteHandlesEmptyDirectory(): void
    {
        $sourceDir = '/test/empty';

        $this->scanner
            ->expects($this->once())
            ->method('scan')
            ->with($sourceDir)
            ->willReturnCallback(function () {
                if (false) {
                    yield; // Empty generator
                }
            });

        $this->messageBus
            ->expects($this->never())
            ->method('dispatch');

        $this->logger
            ->expects($this->exactly(2))
            ->method('info')
            ->withConsecutive(
                [$this->stringContains('Starting file scan')],
                [$this->stringContains('No files found')]
            );

        $commandTester = new \Symfony\Component\Console\Tester\CommandTester($this->command);
        $commandTester->execute([
            'source-directory' => $sourceDir,
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertStringContainsString('No files found', $commandTester->getDisplay());
    }

    public function testExecuteHandlesScannerException(): void
    {
        $sourceDir = '/test/source';

        $this->scanner
            ->expects($this->once())
            ->method('scan')
            ->with($sourceDir)
            ->willThrowException(new \RuntimeException('Scanner error'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('File scan failed'));

        $commandTester = new \Symfony\Component\Console\Tester\CommandTester($this->command);
        $commandTester->execute([
            'source-directory' => $sourceDir,
        ]);

        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    public function testExecuteSkipsNonExistentFiles(): void
    {
        $sourceDir = '/test/source';
        $filePath1 = new FilePath('/test/source/file1.jpg');
        $filePath2 = new FilePath('/test/source/file2.mp4');

        $this->scanner
            ->expects($this->once())
            ->method('scan')
            ->with($sourceDir)
            ->willReturnCallback(function () use ($filePath1, $filePath2) {
                yield $filePath1;
                yield $filePath2;
            });

        $this->filesystem
            ->expects($this->exactly(2))
            ->method('exists')
            ->willReturnOnConsecutiveCalls(true, false);

        $this->filesystem
            ->expects($this->once())
            ->method('getSize')
            ->willReturn(1024);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(FileDiscovered::class))
            ->willReturnCallback(fn($event): Envelope => new Envelope($event));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('File does not exist'));

        $commandTester = new \Symfony\Component\Console\Tester\CommandTester($this->command);
        $commandTester->execute([
            'source-directory' => $sourceDir,
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
    }
}

