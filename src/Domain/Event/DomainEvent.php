<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Domain\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base class for all domain events.
 * All domain events should extend this class.
 */
abstract class DomainEvent extends Event
{
    private readonly \DateTimeImmutable $occurredAt;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
