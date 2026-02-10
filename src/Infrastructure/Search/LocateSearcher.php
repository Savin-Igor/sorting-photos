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
 * File searcher using locate command.
 * Updates locate database before search.
 */
final readonly class LocateSearcher implements FileSearcherPort
{
    public function __construct(
        private LoggerPort $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        try {
            $process = new Process(['which', 'locate']);
            $process->run();

            return $process->isSuccessful() && '' !== \trim($process->getOutput());
        } catch (\Exception) {
            return false;
        }
    }

    public function getName(): string
    {
        return 'locate';
    }

    /**
     * @return iterable<FilePath>
     *
     * @throws SearcherNotAvailableException
     */
    public function search(string $directory, FileSearchCriteria $criteria): iterable
    {
        if (!$this->isAvailable()) {
            throw SearcherNotAvailableException::commandNotAvailable('locate');
        }

        // Update locate database before search
        $this->updateDatabase();

        // Build locate command
        $command = $this->buildLocateCommand($criteria);

        $this->logger->debug('Executing locate command', [
            'command' => \implode(' ', $command),
            'directory' => $directory,
        ]);

        $process = new Process($command);
        $process->setTimeout(300); // 5 minutes timeout
        $process->run();

        if (!$process->isSuccessful()) {
            $error = $process->getErrorOutput();
            $this->logger->error('locate command failed', [
                'error' => $error,
                'exit_code' => $process->getExitCode(),
            ]);
            throw SearcherNotAvailableException::commandFailed('locate', $error);
        }

        $output = $process->getOutput();
        $lines = \array_filter(\explode("\n", $output), fn (string $line): bool => '' !== \trim($line));
        $totalLines = \count($lines);

        // Normalize directory path for comparison
        $normalizedDirectory = \rtrim(\realpath($directory) ?: $directory, '/\\').\DIRECTORY_SEPARATOR;

        $foundCount = 0;
        $filteredOutCount = 0;
        foreach ($lines as $line) {
            $filePath = \trim($line);
            if ('' === $filePath || !\file_exists($filePath)) {
                ++$filteredOutCount;
                continue;
            }

            // Filter: only files within the specified directory
            $normalizedFilePath = \realpath($filePath) ?: $filePath;
            if (!\str_starts_with($normalizedFilePath, $normalizedDirectory)) {
                ++$filteredOutCount;
                continue; // Skip files outside the target directory
            }

            // Additional filtering (size, regex patterns)
            if (!$this->matchesAdditionalCriteria($filePath, $criteria)) {
                ++$filteredOutCount;
                continue;
            }

            yield new FilePath($filePath);
            ++$foundCount;
        }

        $this->logger->info('locate search completed', [
            'directory' => $directory,
            'total_lines' => $totalLines,
            'found_files' => $foundCount,
            'filtered_out' => $filteredOutCount,
        ]);

        // If locate returned many results but none matched the directory,
        // it's likely that the directory is not indexed (e.g., external drive)
        if ($totalLines > 100 && 0 === $foundCount) {
            $this->logger->warning('locate returned many results but none matched the directory - directory may not be indexed', [
                'directory' => $directory,
                'total_results' => $totalLines,
            ]);
        }
    }

    /**
     * Update locate database.
     */
    private function updateDatabase(): void
    {
        try {
            // Check if updatedb is available
            $whichProcess = new Process(['which', 'updatedb']);
            $whichProcess->run();

            if (!$whichProcess->isSuccessful() || '' === \trim($whichProcess->getOutput())) {
                $this->logger->warning('updatedb command is not available, skipping database update');

                return;
            }

            $this->logger->debug('Updating locate database');

            // Run updatedb (may require sudo, but we try without first)
            $process = new Process(['updatedb']);
            $process->setTimeout(600); // 10 minutes timeout for updatedb
            $process->run();

            if ($process->isSuccessful()) {
                $this->logger->debug('locate database updated successfully');
            } else {
                // If updatedb fails (e.g., requires sudo), log warning but continue
                // locate will use existing database
                $this->logger->warning('Failed to update locate database (may require sudo)', [
                    'error' => $process->getErrorOutput(),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Error updating locate database', [
                'error' => $e->getMessage(),
            ]);
            // Continue with existing database
        }
    }

    /**
     * Build locate command with criteria.
     *
     * @return string[]
     */
    private function buildLocateCommand(FileSearchCriteria $criteria): array
    {
        $command = ['locate'];

        // Add case-insensitive flag if available
        $command[] = '-i';

        // Build pattern for extensions
        $patterns = [];

        // Add extension patterns
        if ([] !== $criteria->getExtensions()) {
            $extensionPatterns = \array_map(
                fn (string $ext): string => \sprintf('*.%s', \strtolower($ext)),
                $criteria->getExtensions()
            );
            $patterns = \array_merge($patterns, $extensionPatterns);
        }

        // Add filename patterns (convert regex to glob-like patterns for locate)
        // Note: locate doesn't support full regex, so we use basic patterns
        foreach ($criteria->getFilenamePatterns() as $pattern) {
            // Try to convert simple regex patterns to glob
            // For complex patterns, we'll filter in matchesAdditionalCriteria
            $globPattern = $this->regexToGlob($pattern);
            if (null !== $globPattern) {
                $patterns[] = $globPattern;
            }
        }

        // If no patterns, search for all files in directory
        if ([] === $patterns) {
            $patterns[] = '*';
        }

        // Add patterns to command
        foreach ($patterns as $pattern) {
            $command[] = $pattern;
        }

        return $command;
    }

    /**
     * Convert simple regex pattern to glob pattern for locate.
     * Returns null if conversion is not possible.
     */
    private function regexToGlob(?string $pattern): ?string
    {
        if (null === $pattern || '' === $pattern) {
            return null;
        }

        // Simple conversions:
        // \d{8} -> [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]
        // .* -> *
        // ^ -> (remove)
        // $ -> (remove)

        $glob = $pattern;
        $glob = \preg_replace('/\^/', '', $glob);
        if (null === $glob) {
            return null;
        }
        $glob = \preg_replace('/\$/', '', $glob);
        if (null === $glob) {
            return null;
        }
        $glob = \preg_replace('/\.\*/', '*', $glob);
        if (null === $glob) {
            return null;
        }
        $glob = \preg_replace('/\\\d\{(\d+)\}/', '[0-9]{$1}', $glob);
        if (null === $glob) {
            return null;
        }
        $glob = \preg_replace('/\\\d/', '[0-9]', $glob);
        if (null === $glob) {
            return null;
        }

        // If pattern is too complex, return null (will filter in matchesAdditionalCriteria)
        if (\preg_match('/[\[\](){}|+?]/', $glob)) {
            return null;
        }

        return $glob;
    }

    /**
     * Check if file matches additional criteria (size, regex patterns).
     */
    private function matchesAdditionalCriteria(string $filePath, FileSearchCriteria $criteria): bool
    {
        // Check file size
        if (null !== $criteria->getMinSizeBytes() || null !== $criteria->getMaxSizeBytes()) {
            $fileSize = @\filesize($filePath);
            if (false === $fileSize) {
                return false;
            }

            if (null !== $criteria->getMinSizeBytes() && $fileSize < $criteria->getMinSizeBytes()) {
                return false;
            }

            if (null !== $criteria->getMaxSizeBytes() && $fileSize > $criteria->getMaxSizeBytes()) {
                return false;
            }
        }

        // Check filename regex patterns
        $filename = \basename($filePath);
        foreach ($criteria->getFilenamePatterns() as $pattern) {
            if (\preg_match($pattern, $filename)) {
                return true; // At least one pattern matches
            }
        }

        // If filename patterns are specified but none matched, exclude file
        return [] === $criteria->getFilenamePatterns();
    }
}
