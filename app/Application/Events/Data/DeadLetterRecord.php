<?php

declare(strict_types=1);

namespace App\Application\Events\Data;

use App\Domain\Events\DeadLetterSource;
use Carbon\CarbonImmutable;

/**
 * Carries a safe, bounded dead-letter summary to operator interfaces.
 */
final readonly class DeadLetterRecord
{
    /**
     * Create one dead-letter summary without storing raw payloads or exception
     * messages that may contain credentials or sensitive project data.
     */
    public function __construct(
        public DeadLetterSource $source,
        public string $id,
        public string $eventId,
        public ?string $eventName,
        public int $attempts,
        public CarbonImmutable $failedAt,
        public string $errorType,
    ) {}

    /**
     * Convert the record into one console-table row.
     *
     * @return array<int, int|string>
     */
    public function toTableRow(): array
    {
        return [
            $this->source->value,
            $this->id,
            $this->eventId,
            $this->eventName ?? 'unknown',
            $this->attempts,
            $this->failedAt->toIso8601String(),
            $this->errorType,
        ];
    }
}
