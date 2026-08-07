<?php

declare(strict_types=1);

namespace App\Application\Events\Data;

/**
 * Identifies one outbox row reserved by the current dispatcher invocation.
 */
final readonly class ClaimedOutboxMessage
{
    /**
     * Create immutable dispatcher claim data.
     */
    public function __construct(
        public int $sequence,
        public string $eventId,
        public string $reservationToken,
        public int $dispatchAttempt,
    ) {}
}
