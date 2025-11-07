<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Storage\GooglePhotos;

final readonly class BatchItem
{
    public function __construct(
        private UploadJobId $jobId,
        private string $uploadToken,
        private \DateTimeImmutable $creationTime,
        private string $filename,
        private string $mimeType,
        private bool $isVideo,
        private int $fileSize,
        private bool $processed = false,
        private ?string $error = null,
    ) {
        if ('' === $this->uploadToken) {
            throw new \InvalidArgumentException('Upload token cannot be empty');
        }
        if ('' === $this->filename) {
            throw new \InvalidArgumentException('Filename cannot be empty');
        }
        if ($this->fileSize <= 0) {
            throw new \InvalidArgumentException('File size must be positive');
        }
    }

    public static function fromJob(UploadJob $job): self
    {
        if (null === $job->getUploadToken()) {
            throw new \InvalidArgumentException('Job must have upload token to create BatchItem');
        }

        return new self(
            jobId: $job->getId(),
            uploadToken: $job->getUploadToken(),
            creationTime: $job->getCreationTime(),
            filename: $job->getFilePath()->getBasename(),
            mimeType: $job->getMimeType(),
            isVideo: $job->isVideo(),
            fileSize: $job->getFileSize(),
        );
    }

    public function markProcessed(): self
    {
        return new self(
            jobId: $this->jobId,
            uploadToken: $this->uploadToken,
            creationTime: $this->creationTime,
            filename: $this->filename,
            mimeType: $this->mimeType,
            isVideo: $this->isVideo,
            fileSize: $this->fileSize,
            processed: true,
        );
    }

    public function markFailed(string $error): self
    {
        return new self(
            jobId: $this->jobId,
            uploadToken: $this->uploadToken,
            creationTime: $this->creationTime,
            filename: $this->filename,
            mimeType: $this->mimeType,
            isVideo: $this->isVideo,
            fileSize: $this->fileSize,
            processed: false,
            error: $error,
        );
    }

    public function getJobId(): UploadJobId
    {
        return $this->jobId;
    }

    public function getUploadToken(): string
    {
        return $this->uploadToken;
    }

    public function getCreationTime(): \DateTimeImmutable
    {
        return $this->creationTime;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function isVideo(): bool
    {
        return $this->isVideo;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function isProcessed(): bool
    {
        return $this->processed;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
