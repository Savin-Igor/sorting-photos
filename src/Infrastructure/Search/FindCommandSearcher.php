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
 * File searcher using find command with shell pipes for fast filtering.
 * Uses -iregex for extensions and grep for filename patterns.
 */
final readonly class FindCommandSearcher implements FileSearcherPort
{
    public function __construct(
        private LoggerPort $logger,
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
        // Sequential search with optimized find + grep (shell pipes)
        $command = $this->buildFindCommand($directory, $criteria);
        $this->logger->debug('Executing find command', [
            'command' => $command,
            'directory' => $directory,
        ]);
        // Use fromShellCommandline to support pipes (grep, etc.)
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(600);
        // 10 minutes timeout
        $process->run();
        if (!$process->isSuccessful()) {
            $error = $process->getErrorOutput();
            $this->logger->error('find command failed', [
                'error' => $error,
                'exit_code' => $process->getExitCode(),
                'command' => $command,
            ]);
            throw new SearcherNotAvailableException(\sprintf('find command failed: %s', $error));
        }
        $output = $process->getOutput();
        $lines = \array_filter(\explode("\n", $output), fn (string $line): bool => '' !== \trim($line));
        $totalLines = \count($lines);
        $scannedDirectories = $this->countScannedDirectories($directory);
        $filteredCount = 0;
        foreach ($lines as $line) {
            $filePath = \trim($line);
            if ('' === $filePath || !\file_exists($filePath) || !\is_file($filePath)) {
                ++$filteredCount;
                continue;
            }

            // Additional filtering (only for exclude/include patterns, size already handled in find)
            if (!$this->matchesAdditionalCriteria($filePath, $criteria)) {
                ++$filteredCount;
                continue;
            }

            yield new FilePath($filePath);
            ++$foundCount;
        }
        $this->logger->debug('find filtering completed', [
            'total_lines' => $totalLines,
            'found_files' => $foundCount,
            'filtered_out' => $filteredCount,
        ]);

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
     * Build find command as shell command string (supports pipes).
     * Uses -iregex for extensions and grep for filename patterns for better performance.
     *
     * @return string Shell command string
     */
    private function buildFindCommand(string $directory, FileSearchCriteria $criteria): string
    {
        // Escape directory path for shell
        $escapedDir = \escapeshellarg($directory);

        // Build find command parts
        $findParts = ['find', $escapedDir];

        // Add exclude directory patterns (must be before -type)
        $excludePatterns = [];
        foreach ($criteria->getExcludeDirectories() as $pattern) {
            // Convert **/.git to */.git for find
            $findPattern = \str_replace('**/', '*/', $pattern);
            $excludePatterns[] = \escapeshellarg($findPattern);
        }

        if ([] !== $excludePatterns) {
            $findParts[] = '\\(';
            $findParts[] = \implode(' -o -path ', $excludePatterns);
            $findParts[] = '\\)';
            $findParts[] = '-prune';
            $findParts[] = '-o';
        }

        // Add type filter (files only)
        $findParts[] = '-type';
        $findParts[] = 'f';

        // Add extension filters using -iregex (much faster than multiple -iname)
        if ([] !== $criteria->getExtensions()) {
            $extensions = \array_map(\strtolower(...), $criteria->getExtensions());
            $extensionsRegex = \implode('|', \array_map(preg_quote(...), $extensions));
            $findParts[] = '-regextype';
            $findParts[] = 'posix-extended';
            $findParts[] = '-iregex';
            $findParts[] = \escapeshellarg(\sprintf('.*\\.(%s)$', $extensionsRegex));
        }

        // Add size filters
        if (null !== $criteria->getMinSizeBytes()) {
            $findParts[] = '-size';
            $findParts[] = \sprintf('+%dc', $criteria->getMinSizeBytes());
        }

        if (null !== $criteria->getMaxSizeBytes()) {
            $findParts[] = '-size';
            $findParts[] = \sprintf('-%dc', $criteria->getMaxSizeBytes() + 1);
        }

        // Build the command string
        $command = \implode(' ', $findParts);

        // Add grep for filename patterns (fast filtering in shell)
        if ([] !== $criteria->getFilenamePatterns()) {
            // Combine patterns with | (OR)
            $grepPatterns = [];
            foreach ($criteria->getFilenamePatterns() as $pattern) {
                // Remove regex delimiters and escape for grep -E
                $grepPattern = \trim($pattern, '/');
                $grepPatterns[] = $grepPattern;
            }
            $grepPattern = \implode('|', $grepPatterns);
            $command .= ' | grep -E '.\escapeshellarg($grepPattern);
        }

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
     * Check if file matches additional criteria (exclude/include patterns only).
     * Filename patterns are already handled by grep in shell command.
     */
    private function matchesAdditionalCriteria(string $filePath, FileSearchCriteria $criteria): bool
    {
        // Filename patterns are already filtered by grep in shell command, skip here
        // (This method is called after shell filtering, so if we get here, filename patterns already matched)

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
