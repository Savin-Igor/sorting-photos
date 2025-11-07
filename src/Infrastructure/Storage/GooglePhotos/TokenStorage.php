<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Infrastructure\Storage\GooglePhotos;

use Doctrine\DBAL\Connection;
use SortingPhotosByDate\Ports\LoggerPort;

final readonly class TokenStorage
{
    private const string TABLE_NAME = 'google_photos_tokens';

    public function __construct(
        private Connection $connection,
        private LoggerPort $logger,
    ) {
    }

    /**
     * Save refresh token.
     */
    public function saveRefreshToken(string $refreshToken): void
    {
        $data = [
            'refresh_token' => $refreshToken,
            'updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ];

        // Check if record exists
        $existing = $this->connection->fetchOne(
            'SELECT refresh_token FROM '.self::TABLE_NAME.' LIMIT 1'
        );

        if (false !== $existing) {
            // Update existing record
            $this->connection->executeStatement(
                'UPDATE '.self::TABLE_NAME.' SET refresh_token = ?, updated_at = ?',
                [
                    $data['refresh_token'],
                    $data['updated_at'],
                ]
            );
        } else {
            // Create new record (access_token and expires_at can be NULL)
            $this->connection->insert(self::TABLE_NAME, [
                'refresh_token' => $refreshToken,
                'access_token' => null,
                'expires_at' => null,
                'updated_at' => $data['updated_at'],
            ]);
        }

        $this->logger->debug('Refresh token saved');
    }

    /**
     * Get saved refresh token.
     */
    public function getRefreshToken(): ?string
    {
        $row = $this->connection->fetchAssociative(
            'SELECT refresh_token FROM '.self::TABLE_NAME.' LIMIT 1'
        );

        if (false === $row) {
            return null;
        }

        return $row['refresh_token'] ?? null;
    }

    /**
     * Delete refresh token (e.g., if expired or revoked).
     */
    public function deleteRefreshToken(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM '.self::TABLE_NAME
        );
        $this->logger->debug('Refresh token deleted');
    }

    /**
     * Check if saved refresh token exists.
     */
    public function hasRefreshToken(): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT refresh_token FROM '.self::TABLE_NAME.' WHERE refresh_token IS NOT NULL LIMIT 1'
        );

        return false !== $row;
    }
}
