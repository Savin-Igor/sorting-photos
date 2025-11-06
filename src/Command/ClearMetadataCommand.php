<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\MetadataRepositoryPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'metadata:clear',
    description: 'Clear all metadata from the database (reset processing locks)'
)]
final class ClearMetadataCommand extends Command
{
    public function __construct(
        private readonly MetadataRepositoryPort $repository,
        private readonly LoggerPort $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('metadata:clear')
            ->setDescription('Clear all metadata from the database (reset processing locks)')
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force clear without confirmation'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('force') && !$io->confirm('Are you sure you want to clear all metadata? This will reset all processing locks.', false)) {
            $io->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        $io->warning('Clearing all metadata from database...');

        if ($this->repository->clearAll()) {
            $io->success('All metadata cleared successfully. Files will be reprocessed on next run.');
            $this->logger->info('Metadata database cleared by user command');

            return Command::SUCCESS;
        }

        $io->error('Failed to clear metadata database.');
        $this->logger->error('Failed to clear metadata database');

        return Command::FAILURE;
    }
}
