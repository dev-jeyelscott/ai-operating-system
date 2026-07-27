<?php

declare(strict_types=1);

namespace App\Application\Events\Contracts;

use App\Application\Events\Data\ClaimedOutboxMessage;
use Carbon\CarbonImmutable;

/**
 * Persists dispatcher claims, delivery outcomes, and terminal failures.
 */
interface OutboxDispatchStore
{
    /**
     * Atomically dead-letter expired reservations that exhausted retries.
     */
    public function deadLetterExpiredExhaustedReservations(
        int $maximumAttempts,
        CarbonImmutable $deadLetteredAt,
    ): int;

    /**
     * Atomically reserve the next deliverable messages.
     *
     * @return list<ClaimedOutboxMessage>
     */
    public function claim(
        int $limit,
        int $leaseSeconds,
        int $maximumAttempts,
    ): array;

    /**
     * Mark one successfully queued message as published.
     */
    public function markPublished(
        ClaimedOutboxMessage $message,
    ): bool;

    /**
     * Release a failed message for a bounded future retry.
     */
    public function release(
        ClaimedOutboxMessage $message,
        CarbonImmutable $availableAt,
        string $error,
    ): bool;

    /**
     * Mark one exhausted message as requiring manual recovery.
     */
    public function markDeadLettered(
        ClaimedOutboxMessage $message,
        CarbonImmutable $deadLetteredAt,
        string $error,
    ): bool;
}
