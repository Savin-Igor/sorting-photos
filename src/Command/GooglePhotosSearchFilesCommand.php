<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use SortingPhotosByDate\Application\Service\GooglePhotos\FileScanner;
use SortingPhotosByDate\Domain\Search\FileSearchCriteria;
use SortingPhotosByDate\Ports\Search\FileSearcherPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google-photos:search-files',
    description: 'Quick search for media files and optionally add them to database'
)]
final class GooglePhotosSearchFilesCommand extends Command
{
    /**
     * @param array<string, mixed> $searchConfig
     */
    public function __construct(
        private readonly FileSearcherPort $fileSearcher,
        private readonly FileScanner $fileScanner,
        private readonly array $searchConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'source-dir',
            's',
            InputOption::VALUE_REQUIRED,
            'Source directory to search'
        )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be found without adding to database'
            )
            ->addOption(
                'add-to-db',
                null,
                InputOption::VALUE_NONE,
                'Add found files to database for processing'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Limit number of results'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceDirOption = $input->getOption('source-dir');
        $sourceDir = \is_string($sourceDirOption) ? $sourceDirOption : null;
        $dryRun = $input->getOption('dry-run');
        $addToDb = $input->getOption('add-to-db');
        $limitOption = $input->getOption('limit');
        $limit = \is_string($limitOption) || \is_int($limitOption) ? (int) $limitOption : null;

        if (null === $sourceDir || '' === $sourceDir) {
            $io->error('Source directory is required. Use --source-dir option.');

            return Command::FAILURE;
        }

        if (!\is_dir($sourceDir)) {
            $io->error(\sprintf('Directory does not exist: %s', $sourceDir));

            return Command::FAILURE;
        }

        if (!$dryRun && !$addToDb) {
            $io->warning('Neither --dry-run nor --add-to-db specified. Use --dry-run to preview or --add-to-db to add files.');

            return Command::FAILURE;
        }

        // Build search criteria from command config
        /** @var array<string, mixed> $searchConfigArray */
        $searchConfigArray = $this->searchConfig;
        $criteria = FileSearchCriteria::fromConfig($searchConfigArray);

        $io->title('File Search');
        $io->writeln(\sprintf('Directory: <info>%s</info>', $sourceDir));

        // Get actual searcher name (for hybrid, get active searcher)
        $searcherName = $this->fileSearcher->getName();
        if ('hybrid' === $searcherName && $this->fileSearcher instanceof \SortingPhotosByDate\Infrastructure\Search\HybridSearcher) {
            $activeSearcherName = $this->fileSearcher->getActiveSearcherName();
            if (null !== $activeSearcherName) {
                $searcherName = $activeSearcherName;
            }
        }
        $io->writeln(\sprintf('Searcher: <info>%s</info>', $searcherName));

        // Get strategy from config
        $strategy = $this->searchConfig['strategy'] ?? 'unknown';
        if (!\is_string($strategy)) {
            $strategy = 'unknown';
        }
        $io->writeln(\sprintf('Strategy: <info>%s</info>', $strategy));

        // Show search criteria
        $extensions = $this->searchConfig['extensions'] ?? [];
        if (\is_array($extensions) && [] !== $extensions) {
            $extensionsList = \array_filter($extensions, is_string(...));
            if ([] !== $extensionsList) {
                $io->writeln(\sprintf('Extensions: <info>%s</info>', \implode(', ', \array_slice($extensionsList, 0, 10)).(\count($extensionsList) > 10 ? ' ...' : '')));
            }
        }

        $filenamePatterns = $this->searchConfig['filename_patterns'] ?? [];
        if (\is_array($filenamePatterns) && [] !== $filenamePatterns) {
            $io->writeln(\sprintf('Filename patterns: <info>%d</info> pattern(s)', \count($filenamePatterns)));
        }

        if ($dryRun) {
            $io->note('DRY RUN mode - files will not be added to database');
        }

        $startTime = \microtime(true);
        $foundFiles = [];
        $count = 0;

        try {
            $filePaths = $this->fileSearcher->search($sourceDir, $criteria);

            foreach ($filePaths as $filePath) {
                if (null !== $limit && $count >= $limit) {
                    break;
                }

                $foundFiles[] = $filePath->getPath();
                ++$count;
            }
        } catch (\Exception $e) {
            $io->error(\sprintf('Search failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $duration = \microtime(true) - $startTime;

        // Display results
        $io->section('Search Results');

        // Calculate statistics
        $totalFiles = \count($foundFiles);
        $filesPerSecond = $totalFiles > 0 ? \round($totalFiles / $duration, 2) : 0;

        $io->table(
            ['Metric', 'Value'],
            [
                ['Found Files', \number_format($totalFiles, 0, '.', ' ')],
                ['Duration', \sprintf('%.2f seconds', $duration)],
                ['Files/Second', \number_format($filesPerSecond, 2, '.', ' ')],
            ]
        );

        // Show search directory info
        $io->writeln('');
        $io->writeln(\sprintf('<comment>Search directory:</comment> %s', $sourceDir));
        $io->writeln(\sprintf('<comment>Search method:</comment> %s (%s)', $this->fileSearcher->getName(), $strategy));

        // Show extensions being searched
        $extensionsForDisplay = \is_array($extensions) ? $extensions : [];
        if ([] !== $extensionsForDisplay) {
            $extensionsList = \array_filter($extensionsForDisplay, is_string(...));
            if ([] !== $extensionsList) {
                $io->writeln(\sprintf('<comment>File extensions:</comment> %s', \implode(', ', \array_slice($extensionsList, 0, 10)).(\count($extensionsList) > 10 ? ' ...' : '')));
            }
        }

        if ([] !== $foundFiles) {
            $io->section('Sample Files (first 20)');
            // Show relative paths from source directory
            $relativeFiles = \array_map(function (string $filePath) use ($sourceDir): string {
                $relative = \str_replace($sourceDir, '', $filePath);

                return \ltrim($relative, '/\\');
            }, \array_slice($foundFiles, 0, 20));
            $io->listing($relativeFiles);

            if (\count($foundFiles) > 20) {
                $io->writeln(\sprintf('<comment>... and %s more files</comment>', \number_format(\count($foundFiles) - 20, 0, '.', ' ')));
            }
        } else {
            $io->warning('No files found matching search criteria');
        }

        // Add to database if requested
        if ($addToDb && !$dryRun) {
            $io->section('Adding Files to Database');
            $addedCount = $this->fileScanner->scanAndCreateJobs($sourceDir);

            $io->success(\sprintf('Added <info>%d</info> files to database', $addedCount));
        }

        return Command::SUCCESS;
    }
}
