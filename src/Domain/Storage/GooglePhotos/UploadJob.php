<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Storage\GooglePhotos;

use SortingPhotosByDate\Domain\ValueObjects\FileHash;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;

final readonly class UploadJob
{
    public function __construct(
        private UploadJobId $id,
        private FilePath $filePath,
        private int $fileSize,
        private FileHash $fileHash,
        private string $mimeType,
        private bool $isVideo,
        private UploadState $state,
        private ?ResumableSession $resumableSession,
        private ?string $uploadToken,
        private ?UploadBatchId $batchId,
        private \DateTimeImmutable $creationTime,
        private int $retryCount,
        private ?string $lastError,
        private ?int $lastKnownUploadedBytes,
        private int $sessionExpirationCount,
        private \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {
        if ($this->fileSize <= 0) {
            throw new \InvalidArgumentException('File size must be positive');
        }
        if ('' === $this->mimeType) {
            throw new \InvalidArgumentException('MIME type cannot be empty');
        }
    }

    public static function create(
        FilePath $filePath,
        int $fileSize,
        FileHash $fileHash,
        string $mimeType,
        bool $isVideo,
        \DateTimeImmutable $creationTime,
    ): self {
        $now = new \DateTimeImmutable();

        return new self(
            id: UploadJobId::generate(),
            filePath: $filePath,
            fileSize: $fileSize,
            fileHash: $fileHash,
            mimeType: $mimeType,
            isVideo: $isVideo,
            state: UploadState::PENDING,
            resumableSession: null,
            uploadToken: null,
            batchId: null,
            creationTime: $creationTime,
            retryCount: 0,
            lastError: null,
            lastKnownUploadedBytes: null,
            sessionExpirationCount: 0,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Восстановить объект из данных БД (для репозитория).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $resumableSession = null;
        if (null !== ($data['resumable_session_uri'] ?? null)) {
            $createdAtStr = \is_string($data['created_at'] ?? null) ? $data['created_at'] : 'now';
            $createdAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $createdAtStr) ?: new \DateTimeImmutable();
            $resumableSession = ResumableSession::create(
                sessionUri: $data['resumable_session_uri'],
                totalBytes: (int) $data['file_size'],
                createdAt: $createdAt
            );
            $resumableSession = $resumableSession->withProgress((int) ($data['uploaded_bytes'] ?? 0));
        }

        return new self(
            id: UploadJobId::fromString($data['id']),
            filePath: new FilePath($data['file_path']),
            fileSize: (int) $data['file_size'],
            fileHash: new FileHash($data['file_hash']),
            mimeType: $data['mime_type'],
            isVideo: (bool) $data['is_video'],
            state: UploadState::from($data['state']),
            resumableSession: $resumableSession,
            uploadToken: $data['upload_token'] ?? null,
            batchId: isset($data['batch_id']) ? UploadBatchId::fromString($data['batch_id']) : null,
            creationTime: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['creation_time']) ?: new \DateTimeImmutable(),
            retryCount: (int) ($data['retry_count'] ?? 0),
            lastError: $data['last_error'] ?? null,
            lastKnownUploadedBytes: isset($data['last_known_uploaded_bytes']) ? (int) $data['last_known_uploaded_bytes'] : null,
            sessionExpirationCount: (int) ($data['session_expiration_count'] ?? 0),
            createdAt: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['created_at']) ?: new \DateTimeImmutable(),
            updatedAt: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['updated_at']) ?: new \DateTimeImmutable(),
        );
    }

    public function startUpload(string $sessionUri): self
    {
        $this->assertValidTransition(UploadState::UPLOADING);

        $session = ResumableSession::create(
            sessionUri: $sessionUri,
            totalBytes: $this->fileSize,
            createdAt: new \DateTimeImmutable(),
        );

        return new self(
            id: $this->id,
            filePath: $this->filePath,
            fileSize: $this->fileSize,
            fileHash: $this->fileHash,
            mimeType: $this->mimeType,
            isVideo: $this->isVideo,
            state: UploadState::UPLOADING,
            resumableSession: $session,
            uploadToken: $this->uploadToken,
            batchId: $this->batchId,
            creationTime: $this->creationTime,
            retryCount: $this->retryCount,
            lastError: null,
            lastKnownUploadedBytes: $this->lastKnownUploadedBytes,
            sessionExpirationCount: $this->sessionExpirationCount,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function updateProgress(int $uploadedBytes): self
    {
        if (!$this->resumableSession instanceof ResumableSession) {
            throw new \RuntimeException('Cannot update progress: no resumable session');
        }

        $newSession = $this->resumableSession->withProgress($uploadedBytes);

        return new self(
            id: $this->id,
            filePath: $this->filePath,
            fileSize: $this->fileSize,
            fileHash: $this->fileHash,
            mimeType: $this->mimeType,
            isVideo: $this->isVideo,
            state: $this->state,
            resumableSession: $newSession,
            uploadToken: $this->uploadToken,
            batchId: $this->batchId,
            creationTime: $this->creationTime,
            retryCount: $this->retryCount,
            lastError: $this->lastError,
            lastKnownUploadedBytes: $this->lastKnownUploadedBytes,
            sessionExpirationCount: $this->sessionExpirationCount,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function completeUpload(string $uploadToken): self
    {
        $this->assertValidTransition(UploadState::UPLOADED);

        return new self(
            id: $this->id,
            filePath: $this->filePath,
            fileSize: $this->fileSize,
            fileHash: $this->fileHash,
            mimeType: $this->mimeType,
            isVideo: $this->isVideo,
            state: UploadState::UPLOADED,
            resumableSession: null, // Session no longer needed
            uploadToken: $uploadToken,
            batchId: $this->batchId,
            creationTime: $this->creationTime,
            retryCount: $this->retryCount,
            lastError: null,
            lastKnownUploadedBytes: null,
            sessionExpirationCount: $this->sessionExpirationCount,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function assignToBatch(UploadBatchId $batchId): self
    {
        $this->assertValidTransition(UploadState::IN_BATCH);

        return new self(
            id: $this->id,
            filePath: $this->filePath,
            fileSize: $this->fileSize,
            fileHash: $this->fileHash,
            mimeType: $this->mimeType,
            isVideo: $this->isVideo,
            state: UploadState::IN_BATCH,
            resumableSession: $this->resumableSession,
            uploadToken: $this->uploadToken,
            batchId: $batchId,
            creationTime: $this->creationTime,
            retryCount: $this->retryCount,
            lastError: $this->lastError,
            lastKnownUploadedBytes: $this->lastKnownUploadedBytes,
            sessionExpirationCount: $this->sessionExpirationCount,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function markCompleted(): self
    {
        $this->assertValidTransition(UploadState::COMPLETED);

        return new self(
            id: $this->id,
            filePath: $this->filePath,
            fileSize: $this->fileSize,
            fileHash: $this->fileHash,
            mimeType: $this->mimeType,
            isVideo: $this->isVideo,
            state: UploadState::COMPLETED,
            resumableSession: null,
            uploadToken: $this->uploadToken,
            batchId: $this->batchId,
            creationTime: $this->creationTime,
            retryCount: $this->retryCount,
            lastError: null,
            lastKnownUploadedBytes: null,
            sessionExpirationCount: $this->sessionExpirationCount,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function pauseByQuota(): self
    {
        $this->assertValidTransition(UploadState::PAUSED);

        return new self(
            id: $this->id,
            filePath: $this->filePath,
            fileSize: $this->fileSize,
            fileHash: $this->fileHash,
            mimeType: $this->mimeType,
            isVideo: $this->isVideo,
            state: UploadState::PAUSED,
            resumableSession: $this->resumableSession,
            uploadToken: $this->uploadToken,
            batchId: $this->batchId,
            creationTime: $this->creationTime,
            retryCount: $this->retryCount,
            lastError: 'Quota exceeded',
            lastKnownUploadedBytes: $this->resumableSession?->getUploadedBytes(),
            sessionExpirationCount: $this->sessionExpirationCount,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function resume(): self
    {
        if (UploadState::PAUSED !== $this->state) {
            throw new \RuntimeException('Can only resume paused jobs');
        }

        $newState = $this->resumableSession instanceof ResumableSession ? UploadState::UPLOADING : UploadState::PENDING;

        return new self(
            id: $this->id,
            filePath: $this->filePath,
            fileSize: $this->fileSize,
            fileHash: $this->fileHash,
            mimeType: $this->mimeType,
            isVideo: $this->isVideo,
            state: $newState,
            resumableSession: $this->resumableSession,
            uploadToken: $this->uploadToken,
            batchId: $this->batchId,
            creationTime: $this->creationTime,
            retryCount: $this->retryCount,
            lastError: null,
            lastKnownUploadedBytes: $this->lastKnownUploadedBytes,
            sessionExpirationCount: $this->sessionExpirationCount,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function markSessionExpired(int $lastKnownBytes): self
    {
        return new self(
            id: $this->id,
            filePath: $this->filePath,
            fileSize: $this->fileSize,
            fileHash: $this->fileHash,
            mimeType: $this->mimeType,
            isVideo: $this->isVideo,
            state: UploadState::PENDING, // Return to queue
            resumableSession: null, // Reset session
            uploadToken: null,
            batchId: null,
            creationTime: $this->creationTime,
            retryCount: $this->retryCount,
            lastError: 'Session expired',
            lastKnownUploadedBytes: $lastKnownBytes,
            sessionExpirationCount: $this->sessionExpirationCount + 1,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function resetToUploading(): self
    {
        return new self(
            id: $this->id,
            filePath: $this->filePath,
            fileSize: $this->fileSize,
            fileHash: $this->fileHash,
            mimeType: $this->mimeType,
            isVideo: $this->isVideo,
            state: UploadState::UPLOADING,
            resumableSession: null,
            uploadToken: null,
            batchId: null,
            creationTime: $this->creationTime,
            retryCount: $this->retryCount + 1,
            lastError: null,
            lastKnownUploadedBytes: $this->lastKnownUploadedBytes,
            sessionExpirationCount: $this->sessionExpirationCount,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function markAsFailed(string $error): self
    {
        $this->assertValidTransition(UploadState::FAILED);

        return new self(
            id: $this->id,
            filePath: $this->filePath,
            fileSize: $this->fileSize,
            fileHash: $this->fileHash,
            mimeType: $this->mimeType,
            isVideo: $this->isVideo,
            state: UploadState::FAILED,
            resumableSession: $this->resumableSession,
            uploadToken: $this->uploadToken,
            batchId: $this->batchId,
            creationTime: $this->creationTime,
            retryCount: $this->retryCount,
            lastError: $error,
            lastKnownUploadedBytes: $this->resumableSession?->getUploadedBytes(),
            sessionExpirationCount: $this->sessionExpirationCount,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function canResume(): bool
    {
        return UploadState::PAUSED === $this->state;
    }

    public function needsSessionRenewal(\DateTimeImmutable $now): bool
    {
        return $this->resumableSession instanceof ResumableSession && $this->resumableSession->isExpired($now);
    }

    public function isReadyForBatch(): bool
    {
        return UploadState::UPLOADED === $this->state && !$this->batchId instanceof UploadBatchId;
    }

    private function assertValidTransition(UploadState $newState): void
    {
        if (!$this->state->canTransitionTo($newState)) {
            throw new \RuntimeException(\sprintf('Invalid state transition from %s to %s', $this->state->value, $newState->value));
        }
    }

    public function getId(): UploadJobId
    {
        return $this->id;
    }

    public function getFilePath(): FilePath
    {
        return $this->filePath;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getFileHash(): FileHash
    {
        return $this->fileHash;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function isVideo(): bool
    {
        return $this->isVideo;
    }

    public function getState(): UploadState
    {
        return $this->state;
    }

    public function getResumableSession(): ?ResumableSession
    {
        return $this->resumableSession;
    }

    public function getUploadToken(): ?string
    {
        return $this->uploadToken;
    }

    public function getBatchId(): ?UploadBatchId
    {
        return $this->batchId;
    }

    public function getCreationTime(): \DateTimeImmutable
    {
        return $this->creationTime;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastKnownUploadedBytes(): ?int
    {
        return $this->lastKnownUploadedBytes;
    }

    public function getSessionExpirationCount(): int
    {
        return $this->sessionExpirationCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
