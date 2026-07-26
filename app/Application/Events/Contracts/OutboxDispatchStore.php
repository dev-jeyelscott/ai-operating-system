<?php

declare(strict_types=1);

namespace App\Application\Events\Contracts;

use App\Application\Events\Data\ClaimedOutboxMessage;
use Carbon\CarbonImmutable;

/**
 * Persists dispatcher claims and delivery outcomes.
 */
interface OutboxDispatchStore
{
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
}
