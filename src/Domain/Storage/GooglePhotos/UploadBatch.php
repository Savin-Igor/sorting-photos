<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Storage\GooglePhotos;

final readonly class UploadBatch
{
    public function __construct(
        private UploadBatchId $id,
        private BatchState $state,
        private array $items, // BatchItem[]
        private int $currentIndex,
        private int $totalSize,
        private ?string $errorMessage,
        private ?\DateTimeImmutable $quotaResetTime,
        private \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $startedAt,
        private ?\DateTimeImmutable $completedAt,
        private \DateTimeImmutable $updatedAt,
    ) {
        if ([] === $this->items) {
            throw new \InvalidArgumentException('Batch must have at least one item');
        }
        if ($this->currentIndex < 0) {
            throw new \InvalidArgumentException('Current index cannot be negative');
        }
        if ($this->currentIndex > \count($this->items)) {
            throw new \InvalidArgumentException('Current index cannot exceed items count');
        }
        if ($this->totalSize <= 0) {
            throw new \InvalidArgumentException('Total size must be positive');
        }
    }

    public static function create(array $items): self
    {
        if ([] === $items) {
            throw new \InvalidArgumentException('Cannot create batch with empty items');
        }

        $totalSize = \array_sum(\array_map(fn (BatchItem $item): int => $item->getFileSize(), $items));
        $now = new \DateTimeImmutable();

        return new self(
            id: UploadBatchId::generate(),
            state: BatchState::READY,
            items: $items,
            currentIndex: 0,
            totalSize: $totalSize,
            errorMessage: null,
            quotaResetTime: null,
            createdAt: $now,
            startedAt: null,
            completedAt: null,
            updatedAt: $now,
        );
    }

    /**
     * Восстановить объект из данных БД (для репозитория).
     *
     * @param array<string, mixed> $data
     * @param array<BatchItem>     $items
     */
    public static function fromArray(array $data, array $items): self
    {
        return new self(
            id: UploadBatchId::fromString($data['id']),
            state: BatchState::from($data['state']),
            items: $items,
            currentIndex: (int) ($data['current_index'] ?? 0),
            totalSize: (int) $data['total_size'],
            errorMessage: $data['error_message'] ?? null,
            quotaResetTime: isset($data['quota_reset_time']) ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['quota_reset_time']) : null,
            createdAt: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['created_at']) ?: new \DateTimeImmutable(),
            startedAt: isset($data['started_at']) ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['started_at']) : null,
            completedAt: isset($data['completed_at']) ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['completed_at']) : null,
            updatedAt: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['updated_at']) ?: new \DateTimeImmutable(),
        );
    }

    public function startProcessing(): self
    {
        $this->assertValidTransition(BatchState::PROCESSING);

        return new self(
            id: $this->id,
            state: BatchState::PROCESSING,
            items: $this->items,
            currentIndex: $this->currentIndex,
            totalSize: $this->totalSize,
            errorMessage: $this->errorMessage,
            quotaResetTime: $this->quotaResetTime,
            createdAt: $this->createdAt,
            startedAt: $this->startedAt ?? new \DateTimeImmutable(),
            completedAt: $this->completedAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function markItemProcessed(int $index): self
    {
        if ($index < 0 || $index >= \count($this->items)) {
            throw new \InvalidArgumentException('Invalid item index');
        }

        $items = $this->items;
        $items[$index] = $items[$index]->markProcessed();

        $newCurrentIndex = \max($this->currentIndex, $index + 1);
        $newState = $this->allItemsProcessed($items) ? BatchState::COMPLETED : $this->state;

        return new self(
            id: $this->id,
            state: $newState,
            items: $items,
            currentIndex: $newCurrentIndex,
            totalSize: $this->totalSize,
            errorMessage: $this->errorMessage,
            quotaResetTime: $this->quotaResetTime,
            createdAt: $this->createdAt,
            startedAt: $this->startedAt,
            completedAt: BatchState::COMPLETED === $newState ? new \DateTimeImmutable() : $this->completedAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function markItemFailed(int $index, string $error): self
    {
        if ($index < 0 || $index >= \count($this->items)) {
            throw new \InvalidArgumentException('Invalid item index');
        }

        $items = $this->items;
        $items[$index] = $items[$index]->markFailed($error);

        return new self(
            id: $this->id,
            state: $this->state,
            items: $items,
            currentIndex: $this->currentIndex,
            totalSize: $this->totalSize,
            errorMessage: $this->errorMessage,
            quotaResetTime: $this->quotaResetTime,
            createdAt: $this->createdAt,
            startedAt: $this->startedAt,
            completedAt: $this->completedAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function pause(\DateTimeImmutable $resetTime): self
    {
        $this->assertValidTransition(BatchState::PAUSED);

        return new self(
            id: $this->id,
            state: BatchState::PAUSED,
            items: $this->items,
            currentIndex: $this->currentIndex,
            totalSize: $this->totalSize,
            errorMessage: 'Quota exceeded',
            quotaResetTime: $resetTime,
            createdAt: $this->createdAt,
            startedAt: $this->startedAt,
            completedAt: $this->completedAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function resume(): self
    {
        if (BatchState::PAUSED !== $this->state) {
            throw new \RuntimeException('Can only resume paused batches');
        }

        $this->assertValidTransition(BatchState::PROCESSING);

        return new self(
            id: $this->id,
            state: BatchState::PROCESSING,
            items: $this->items,
            currentIndex: $this->currentIndex,
            totalSize: $this->totalSize,
            errorMessage: null,
            quotaResetTime: null,
            createdAt: $this->createdAt,
            startedAt: $this->startedAt ?? new \DateTimeImmutable(),
            completedAt: $this->completedAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function complete(): self
    {
        $this->assertValidTransition(BatchState::COMPLETED);

        return new self(
            id: $this->id,
            state: BatchState::COMPLETED,
            items: $this->items,
            currentIndex: $this->currentIndex,
            totalSize: $this->totalSize,
            errorMessage: null,
            quotaResetTime: null,
            createdAt: $this->createdAt,
            startedAt: $this->startedAt,
            completedAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function markAsFailed(string $error): self
    {
        $this->assertValidTransition(BatchState::FAILED);

        return new self(
            id: $this->id,
            state: BatchState::FAILED,
            items: $this->items,
            currentIndex: $this->currentIndex,
            totalSize: $this->totalSize,
            errorMessage: $error,
            quotaResetTime: $this->quotaResetTime,
            createdAt: $this->createdAt,
            startedAt: $this->startedAt,
            completedAt: $this->completedAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function getItemsToProcess(): array
    {
        return \array_slice($this->items, $this->currentIndex);
    }

    public function getFailedItems(): array
    {
        return \array_filter($this->items, fn (BatchItem $item): bool => null !== $item->getError());
    }

    public function allItemsProcessed(?array $items = null): bool
    {
        $itemsToCheck = $items ?? $this->items;

        return \count($itemsToCheck) === \count(\array_filter($itemsToCheck, fn (BatchItem $item): bool => $item->isProcessed()));
    }

    public function hasVideo(): bool
    {
        return \count(\array_filter($this->items, fn (BatchItem $item): bool => $item->isVideo())) > 0;
    }

    private function assertValidTransition(BatchState $newState): void
    {
        if (!$this->state->canTransitionTo($newState)) {
            throw new \RuntimeException(\sprintf('Invalid state transition from %s to %s', $this->state->value, $newState->value));
        }
    }

    public function getId(): UploadBatchId
    {
        return $this->id;
    }

    public function getState(): BatchState
    {
        return $this->state;
    }

    /**
     * @return array<BatchItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getCurrentIndex(): int
    {
        return $this->currentIndex;
    }

    public function getTotalSize(): int
    {
        return $this->totalSize;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getQuotaResetTime(): ?\DateTimeImmutable
    {
        return $this->quotaResetTime;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
