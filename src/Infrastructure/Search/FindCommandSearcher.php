<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Search;

use SortingPhotosByDate\Domain\Search\FileSearchCriteria;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Search\FileSearcherPort;
use SortingPhotosByDate\Ports\Search\SearcherNotAvailableException;
use Symfony\Component\Process\Process;

/**
 * File searcher using find command.
 * Supports parallel search across subdirectories.
 */
final readonly class FindCommandSearcher implements FileSearcherPort
{
    public function __construct(
        private LoggerPort $logger,
        private bool $parallelEnabled = false,
        private int $maxDepth = 3,
    ) {
    }

    public function isAvailable(): bool
    {
        try {
            $process = new Process(['which', 'find']);
            $process->run();

            return $process->isSuccessful() && '' !== \trim($process->getOutput());
        } catch (\Exception) {
            return false;
        }
    }

    public function getName(): string
    {
        return 'find';
    }

    /**
     * @return iterable<FilePath>
     *
     * @throws SearcherNotAvailableException
     */
    public function search(string $directory, FileSearchCriteria $criteria): iterable
    {
        if (!$this->isAvailable()) {
            throw new SearcherNotAvailableException('find command is not available');
        }

        if (!\is_dir($directory)) {
            throw new \InvalidArgumentException(\sprintf('Directory does not exist: %s', $directory));
        }

        $startTime = \microtime(true);
        $foundCount = 0;
        $scannedDirectories = 0;

        if ($this->parallelEnabled && $this->maxDepth > 0) {
            // Parallel search
            foreach ($this->searchParallel($directory, $criteria) as $filePath) {
                yield $filePath;
                ++$foundCount;
            }
        } else {
            // Sequential search
            $command = $this->buildFindCommand($directory, $criteria);

            $this->logger->debug('Executing find command', [
                'command' => \implode(' ', $command),
                'directory' => $directory,
            ]);

            $process = new Process($command);
            $process->setTimeout(600); // 10 minutes timeout
            $process->run();

            if (!$process->isSuccessful()) {
                $error = $process->getErrorOutput();
                $this->logger->error('find command failed', [
                    'error' => $error,
                    'exit_code' => $process->getExitCode(),
                ]);
                throw new SearcherNotAvailableException(\sprintf('find command failed: %s', $error));
            }

            $output = $process->getOutput();
            $lines = \array_filter(\explode("\n", $output), fn (string $line): bool => '' !== \trim($line));
            $scannedDirectories = $this->countScannedDirectories($directory);

            foreach ($lines as $line) {
                $filePath = \trim($line);
                if ('' === $filePath || !\file_exists($filePath) || !\is_file($filePath)) {
                    continue;
                }

                // Additional filtering (size, regex patterns)
                if (!$this->matchesAdditionalCriteria($filePath, $criteria)) {
                    continue;
                }

                yield new FilePath($filePath);
                ++$foundCount;
            }
        }

        $duration = \microtime(true) - $startTime;

        $this->logger->info('find search completed', [
            'directory' => $directory,
            'found_files' => $foundCount,
            'scanned_directories' => $scannedDirectories,
            'duration_seconds' => \round($duration, 2),
            'files_per_second' => $foundCount > 0 ? \round($foundCount / $duration, 2) : 0,
        ]);
    }

    /**
     * Parallel search across subdirectories.
     *
     * @return iterable<FilePath>
     */
    private function searchParallel(string $directory, FileSearchCriteria $criteria): iterable
    {
        $subdirectories = $this->getSubdirectories($directory, $this->maxDepth);
        $processes = [];
        $results = [];

        $this->logger->debug('Starting parallel search', [
            'directory' => $directory,
            'subdirectories_count' => \count($subdirectories),
            'max_depth' => $this->maxDepth,
        ]);

        // Start processes for each subdirectory
        foreach ($subdirectories as $subdir) {
            $command = $this->buildFindCommand($subdir, $criteria);
            $process = new Process($command);
            $process->setTimeout(300); // 5 minutes per subdirectory
            $process->start();
            $processes[$subdir] = $process;
        }

        // Collect results as processes complete
        while ([] !== $processes) {
            foreach ($processes as $subdir => $process) {
                if (!$process->isRunning()) {
                    if ($process->isSuccessful()) {
                        $output = $process->getOutput();
                        $lines = \array_filter(\explode("\n", $output), fn (string $line): bool => '' !== \trim($line));

                        foreach ($lines as $line) {
                            $filePath = \trim($line);
                            if ('' === $filePath || !\file_exists($filePath) || !\is_file($filePath)) {
                                continue;
                            }

                            // Additional filtering
                            if (!$this->matchesAdditionalCriteria($filePath, $criteria)) {
                                continue;
                            }

                            $results[] = new FilePath($filePath);
                        }
                    } else {
                        $this->logger->warning('find process failed for subdirectory', [
                            'subdirectory' => $subdir,
                            'error' => $process->getErrorOutput(),
                        ]);
                    }

                    unset($processes[$subdir]);
                }
            }

            // Small delay to avoid busy waiting
            if ([] !== $processes) {
                \usleep(10000); // 10ms
            }
        }

        // Yield results
        foreach ($results as $filePath) {
            yield $filePath;
        }
    }

    /**
     * Get subdirectories up to max depth.
     *
     * @return string[]
     */
    private function getSubdirectories(string $directory, int $maxDepth): array
    {
        $subdirectories = [$directory];
        $currentLevel = [$directory];
        $depth = 0;

        while ($depth < $maxDepth && [] !== $currentLevel) {
            $nextLevel = [];

            foreach ($currentLevel as $dir) {
                try {
                    $items = @\scandir($dir);
                    if (false === $items) {
                        continue;
                    }

                    foreach ($items as $item) {
                        if ('.' === $item || '..' === $item) {
                            continue;
                        }

                        $path = $dir.\DIRECTORY_SEPARATOR.$item;
                        if (\is_dir($path)) {
                            $nextLevel[] = $path;
                            $subdirectories[] = $path;
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Error scanning directory', [
                        'directory' => $dir,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $currentLevel = $nextLevel;
            ++$depth;
        }

        return $subdirectories;
    }

    /**
     * Build find command with criteria.
     *
     * @return string[]
     */
    private function buildFindCommand(string $directory, FileSearchCriteria $criteria): array
    {
        $command = ['find', $directory];

        // Add type filter (files only)
        $command[] = '-type';
        $command[] = 'f';

        // Add extension filters
        if ([] !== $criteria->getExtensions()) {
            $command[] = '(';
            $command[] = '-iname';
            $command[] = \sprintf('*.%s', $criteria->getExtensions()[0]);
            $counter = \count($criteria->getExtensions());
            for ($i = 1; $i < $counter; ++$i) {
                $command[] = '-o';
                $command[] = '-iname';
                $command[] = \sprintf('*.%s', $criteria->getExtensions()[$i]);
            }
            $command[] = ')';
        }

        // Add size filters
        if (null !== $criteria->getMinSizeBytes()) {
            $command[] = '-size';
            $command[] = \sprintf('+%dc', $criteria->getMinSizeBytes());
        }

        if (null !== $criteria->getMaxSizeBytes()) {
            $command[] = '-size';
            $command[] = \sprintf('-%dc', $criteria->getMaxSizeBytes() + 1);
        }

        // Add exclude directory patterns
        foreach ($criteria->getExcludeDirectories() as $pattern) {
            $command[] = '-path';
            $command[] = $pattern;
            $command[] = '-prune';
            $command[] = '-o';
        }

        // Print results
        $command[] = '-print';

        return $command;
    }

    /**
     * Count scanned directories (approximate).
     */
    private function countScannedDirectories(string $directory): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isDir()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Check if file matches additional criteria (regex patterns).
     */
    private function matchesAdditionalCriteria(string $filePath, FileSearchCriteria $criteria): bool
    {
        // Check filename regex patterns
        $filename = \basename($filePath);
        foreach ($criteria->getFilenamePatterns() as $pattern) {
            if (\preg_match($pattern, $filename)) {
                return true; // At least one pattern matches
            }
        }

        // If filename patterns are specified but none matched, exclude file
        if ([] !== $criteria->getFilenamePatterns()) {
            return false;
        }

        // Check exclude patterns (glob)
        foreach ($criteria->getExcludePatterns() as $pattern) {
            if ($this->matchesGlob($filePath, $pattern)) {
                return false;
            }
        }

        // Check include patterns (glob)
        if ([] !== $criteria->getIncludePatterns()) {
            $matches = array_any($criteria->getIncludePatterns(), fn (string $pattern): bool => $this->matchesGlob($filePath, $pattern));
            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if path matches glob pattern.
     */
    private function matchesGlob(string $path, string $pattern): bool
    {
        // Convert glob pattern to regex
        $regex = \str_replace(['*', '?'], ['.*', '.'], \preg_quote($pattern, '/'));
        $regex = '/^'.$regex.'$/';

        return (bool) \preg_match($regex, $path);
    }
}
