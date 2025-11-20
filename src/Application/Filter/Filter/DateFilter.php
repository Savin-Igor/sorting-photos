<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Filter\Filter;

use Carbon\Carbon;
use SortingPhotosByDate\Application\Filter\FileFilterInterface;
use SortingPhotosByDate\Domain\ValueObjects\FilePath;
use SortingPhotosByDate\Infrastructure\Metadata\FilenameDateExtractor;

/**
 * Filter by creation date.
 * Checks date from filename and/or metadata.
 */
final readonly class DateFilter implements FileFilterInterface
{
    /**
     * @param FilenameDateExtractor   $filenameDateExtractor Extractor for filename dates
     * @param \DateTimeImmutable|null $fromDate              Start of date range (null = no limit)
     * @param \DateTimeImmutable|null $toDate                End of date range (null = no limit)
     * @param bool                    $checkFilename         Check date in filename
     * @param bool                    $checkMetadata         Check date in metadata
     */
    public function __construct(
        private FilenameDateExtractor $filenameDateExtractor,
        private ?\DateTimeImmutable $fromDate = null,
        private ?\DateTimeImmutable $toDate = null,
        private bool $checkFilename = true,
        private bool $checkMetadata = true,
    ) {
    }

    #[\Override]
    public function shouldSkip(FilePath $filePath, array $context = []): bool
    {
        $fileDate = null;

        // 1. Check date from filename (if enabled)
        if ($this->checkFilename) {
            $filenameDate = $this->filenameDateExtractor->extract($filePath->getPath());
            if ($filenameDate instanceof Carbon) {
                $fileDate = \DateTimeImmutable::createFromMutable($filenameDate->toDateTime());
            }
        }

        // 2. Check date from metadata (if enabled and filename date not found)
        if (null === $fileDate && $this->checkMetadata && isset($context['creation_date'])) {
            $creationDate = $context['creation_date'];
            if ($creationDate instanceof \DateTimeImmutable) {
                $fileDate = $creationDate;
            } elseif ($creationDate instanceof Carbon) {
                $fileDate = \DateTimeImmutable::createFromMutable($creationDate->toDateTime());
            }
        }

        // If date cannot be determined - skip filter (don't block file)
        if (null === $fileDate) {
            return false;
        }

        // Check date range
        if ($this->fromDate instanceof \DateTimeImmutable && $fileDate < $this->fromDate) {
            return true; // File earlier than start date
        }

        if ($this->toDate instanceof \DateTimeImmutable && $fileDate > $this->toDate) {
            return true; // File later than end date
        }

        return false; // File in range - allow processing
    }

    #[\Override]
    public function getName(): string
    {
        return 'date';
    }

    #[\Override]
    public function canFilterEarly(): bool
    {
        return $this->checkFilename; // Can work early if checking filename
    }
}
