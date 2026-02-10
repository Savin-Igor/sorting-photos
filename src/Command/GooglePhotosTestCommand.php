<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\TokenManager;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\GooglePhotosApiClientPort;
use SortingPhotosByDate\Ports\LoggerPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google-photos:test',
    description: 'Test Google Photos API connection and retrieve media items'
)]
final class GooglePhotosTestCommand extends Command
{
    public function __construct(
        private readonly GooglePhotosApiClientPort $apiClient,
        private readonly TokenManager $tokenManager,
        private readonly LoggerPort $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('albums', null, InputOption::VALUE_NONE, 'List albums instead of media items')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit number of items to retrieve', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Google Photos API Test');

        // Check for refresh token
        if (!$this->tokenManager->hasRefreshToken()) {
            $io->error([
                'No refresh token found. Please authorize first:',
                'php bin/console google-photos:authorize',
            ]);

            return Command::FAILURE;
        }

        try {
            // Test access token retrieval
            $io->section('Step 1: Testing Access Token');
            $io->writeln('Getting access token...');

            try {
                $accessToken = $this->tokenManager->getValidAccessToken();
                $io->success('Access token obtained successfully');
                $io->writeln(\sprintf('Token preview: %s...', \substr($accessToken, 0, 20)));
            } catch (\Exception $e) {
                $io->error([
                    'Failed to get access token:',
                    $e->getMessage(),
                ]);

                return Command::FAILURE;
            }

            // Test data retrieval
            $listAlbums = $input->getOption('albums');
            $limit = (int) $input->getOption('limit');

            if ($listAlbums) {
                return $this->testAlbums($io, $limit);
            }

            return $this->testMediaItems($io, $limit);
        } catch (\Exception $e) {
            $io->error([
                'Test failed:',
                $e->getMessage(),
            ]);

            $this->logger->error('Google Photos API test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    private function testMediaItems(SymfonyStyle $io, int $limit): int
    {
        $io->section('Step 2: Retrieving Media Items');

        try {
            $result = $this->apiClient->listMediaItems(\min($limit, 25));

            $mediaItems = $result['mediaItems'];
            $nextPageToken = $result['nextPageToken'];

            if (0 === \count($mediaItems)) {
                $io->warning('No media items found in your Google Photos library');
                $io->note('Try uploading some photos first or check your API permissions.');

                return Command::SUCCESS;
            }

            $io->success(\sprintf('Retrieved %d media item(s)', \count($mediaItems)));

            $io->section('Media Items');
            foreach ($mediaItems as $index => $item) {
                $io->writeln([
                    \sprintf('<fg=cyan>Item %d:</>', $index + 1),
                    \sprintf('  ID: %s', $item->getId()),
                    \sprintf('  URL: %s', $item->getProductUrl()),
                    '',
                ]);
            }

            if (null !== $nextPageToken) {
                $io->note(\sprintf('There are more items available (nextPageToken: %s...)', \substr($nextPageToken, 0, 20)));
            }

            $io->success('Google Photos API connection test completed successfully!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error([
                'Failed to retrieve media items:',
                $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    private function testAlbums(SymfonyStyle $io, int $limit): int
    {
        $io->section('Step 2: Retrieving Albums');

        try {
            $result = $this->apiClient->listAlbums(\min($limit, 50));

            $albums = $result['albums'];
            $nextPageToken = $result['nextPageToken'];

            if (0 === \count($albums)) {
                $io->warning('No albums found in your Google Photos library');
                $io->note('Try creating some albums first or check your API permissions.');

                return Command::SUCCESS;
            }

            $io->success(\sprintf('Retrieved %d album(s)', \count($albums)));

            $io->section('Albums');
            foreach ($albums as $index => $album) {
                $io->writeln([
                    \sprintf('<fg=cyan>Album %d:</>', $index + 1),
                    \sprintf('  ID: %s', $album['id']),
                    \sprintf('  Title: %s', $album['title']),
                    \sprintf('  URL: %s', $album['productUrl']),
                    '',
                ]);
            }

            if (null !== $nextPageToken) {
                $io->note(\sprintf('There are more albums available (nextPageToken: %s...)', \substr($nextPageToken, 0, 20)));
            }

            $io->success('Google Photos API connection test completed successfully!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error([
                'Failed to retrieve albums:',
                $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
