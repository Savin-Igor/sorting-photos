<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Command;

use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\CredentialsFactory;
use SortingPhotosByDate\Infrastructure\Storage\GooglePhotos\TokenManager;
use SortingPhotosByDate\Ports\LoggerPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google-photos:authorize',
    description: 'Authorize application with Google Photos API using OAuth 2.0'
)]
final class GooglePhotosAuthorizeCommand extends Command
{
    public function __construct(
        private readonly CredentialsFactory $credentialsFactory,
        private readonly TokenManager $tokenManager,
        private readonly LoggerPort $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('code', null, InputOption::VALUE_REQUIRED, 'Authorization code from Google (for callback)')
            ->addOption('full-access', null, InputOption::VALUE_NONE, 'Request full access scope (photoslibrary) instead of appendonly');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // If authorization code is provided - exchange for tokens
        $code = $input->getOption('code');
        if (null !== $code) {
            return $this->handleCallback($io, $code, $input->getOption('full-access'));
        }

        // If refresh token already exists - show status
        if ($this->tokenManager->hasRefreshToken()) {
            $io->info('Refresh token already exists. Use --code option to update it.');
            $io->note('To re-authorize, you may need to revoke existing tokens first.');

            return Command::SUCCESS;
        }

        // Show authorization URL
        $fullAccess = $input->getOption('full-access');
        $authUrl = $this->credentialsFactory->getAuthorizationUrl($fullAccess);

        $io->title('Google Photos API Authorization');
        $io->section('Step 1: Authorize Application');

        // Check redirect type
        $isOob = str_contains($authUrl, 'urn%3Aietf%3Awg%3Aoauth%3A2.0%3Aoob') || str_contains($authUrl, 'urn:ietf:wg:oauth:2.0:oob');
        $isLocalhostCallback = str_contains($authUrl, 'localhost:8080/oauth/callback');

        $io->writeln([
            '',
            '1. Open the following URL in your browser:',
            '',
            "   <fg=cyan>{$authUrl}</>",
            '',
            '2. Sign in with your Google account',
            '3. Grant permissions to the application',
        ]);

        if ($isOob) {
            $io->writeln([
                '4. Google will show the authorization code on the page',
                '5. Copy the code from the page',
            ]);
        } elseif ($isLocalhostCallback) {
            $io->writeln([
                '4. Google will redirect to http://localhost:8080/oauth/callback?code=...',
                '5. Copy the code parameter from the URL (even if page shows error)',
                '   Example: if URL is http://localhost:8080/oauth/callback?code=4/0AeanS...',
                '   Copy only the code part: "4/0AeanS..."',
            ]);
            $io->warning([
                'IMPORTANT: Make sure http://localhost:8080/oauth/callback is added',
                'to Authorized redirect URIs in Google Cloud Console!',
            ]);
        } else {
            $io->writeln([
                '4. Copy the authorization code from the redirect URL (parameter "code")',
            ]);
        }

        $io->writeln([
            '',
            '6. Run this command again with the --code option:',
            '',
            '   <fg=yellow>php bin/console google-photos:authorize --code=YOUR_CODE</>',
            '',
        ]);

        $io->note([
            'Scope requested: '.($fullAccess ? 'Full access (photoslibrary)' : 'Append only (photoslibrary.appendonly)'),
        ]);

        return Command::SUCCESS;
    }

    private function handleCallback(SymfonyStyle $io, string $code, bool $fullAccess): int
    {
        if ('' === $code) {
            $io->error('Authorization code is required');

            return Command::FAILURE;
        }

        $io->section('Step 2: Exchanging Authorization Code for Tokens');

        try {
            $tokens = $this->credentialsFactory->exchangeCodeForTokens($code, $fullAccess);

            if ('' === $tokens['refresh_token']) {
                $io->warning([
                    'No refresh token received. This may happen if you have already authorized the application.',
                    'Try revoking access and authorizing again, or use --full-access flag.',
                ]);

                return Command::FAILURE;
            }

            $this->tokenManager->saveRefreshToken($tokens['refresh_token']);

            $io->success([
                'Authorization successful!',
                '',
                'Refresh token has been saved:',
                '- Access Token: expires in '.$tokens['expires_in'].' seconds',
                '- Refresh Token: saved for long-term use',
                '',
                'You can now use the upload command.',
            ]);

            $this->logger->info('Google Photos authorization completed successfully');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error([
                'Failed to exchange authorization code:',
                $e->getMessage(),
            ]);

            $this->logger->error('Authorization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
