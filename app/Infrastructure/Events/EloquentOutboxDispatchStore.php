<?php

declare(strict_types=1);

namespace App\Infrastructure\Events;

use App\Application\Events\Contracts\OutboxDispatchStore;
use App\Application\Events\Data\ClaimedOutboxMessage;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recovers and claims outbox rows through PostgreSQL-safe updates and locks.
 */
final class EloquentOutboxDispatchStore implements OutboxDispatchStore
{
    private const EXPIRED_EXHAUSTED_RESERVATION_ERROR =
        'Outbox reservation expired after the maximum delivery attempts.';

    /**
     * Atomically move expired exhausted reservations into dead-letter state.
     *
     * The guarded bulk update ensures concurrent dispatchers can only recover
     * rows that are still unpublished and have not already been dead-lettered.
     */
    public function deadLetterExpiredExhaustedReservations(
        int $maximumAttempts,
        CarbonImmutable $deadLetteredAt,
    ): int {
        return OutboxMessage::query()
            ->whereNull('published_at')
            ->whereNull('dead_lettered_at')
            ->where(
                'dispatch_attempts',
                '>=',
                $maximumAttempts,
            )
            ->whereNotNull('reserved_until')
            ->where(
                'reserved_until',
                '<=',
                $deadLetteredAt,
            )
            ->update([
                'available_at' => $deadLetteredAt,
                'reservation_token' => null,
                'reserved_until' => null,
                'last_error' => self::EXPIRED_EXHAUSTED_RESERVATION_ERROR,
                'dead_lettered_at' => $deadLetteredAt,
            ]);
    }

    /**
     * Atomically reserve the oldest deliverable rows.
     *
     * FOR UPDATE SKIP LOCKED allows multiple dispatcher processes to claim
     * different rows without waiting for each other or duplicating a claim.
     *
     * @return list<ClaimedOutboxMessage>
     */
    public function claim(
        int $limit,
        int $leaseSeconds,
        int $maximumAttempts,
    ): array {
        return DB::transaction(
            function () use (
                $limit,
                $leaseSeconds,
                $maximumAttempts,
            ): array {
                $now = CarbonImmutable::now();

                $messages = OutboxMessage::query()
                    ->whereNull('published_at')
                    ->whereNull('dead_lettered_at')
                    ->where(
                        'dispatch_attempts',
                        '<',
                        $maximumAttempts,
                    )
                    ->where('available_at', '<=', $now)
                    ->where(
                        function (Builder $query) use ($now): void {
                            $query
                                ->whereNull('reserved_until')
                                ->orWhere(
                                    'reserved_until',
                                    '<=',
                                    $now,
                                );
                        },
                    )
                    ->orderBy('sequence')
                    ->limit($limit)
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->get();

                $claims = [];

                foreach ($messages as $message) {
                    $reservationToken = Str::uuid()->toString();
                    $dispatchAttempt = $message->dispatch_attempts + 1;

                    $message->forceFill([
                        'reservation_token' => $reservationToken,
                        'reserved_until' => $now->addSeconds(
                            $leaseSeconds,
                        ),
                        'dispatch_attempts' => $dispatchAttempt,
                        'last_error' => null,
                    ])->save();

                    $claims[] = new ClaimedOutboxMessage(
                        sequence: $message->sequence,
                        eventId: $message->event_id,
                        reservationToken: $reservationToken,
                        dispatchAttempt: $dispatchAttempt,
                    );
                }

                return $claims;
            },
            attempts: 3,
        );
    }

    /**
     * Record that the reserved message was accepted by the queue transport.
     */
    public function markPublished(
        ClaimedOutboxMessage $message,
    ): bool {
        return OutboxMessage::query()
            ->whereKey($message->sequence)
            ->where(
                'reservation_token',
                $message->reservationToken,
            )
            ->whereNull('published_at')
            ->whereNull('dead_lettered_at')
            ->update([
                'published_at' => CarbonImmutable::now(),
                'reservation_token' => null,
                'reserved_until' => null,
                'last_error' => null,
            ]) === 1;
    }

    /**
     * Release a failed reservation for a bounded later attempt.
     */
    public function release(
        ClaimedOutboxMessage $message,
        CarbonImmutable $availableAt,
        string $error,
    ): bool {
        return OutboxMessage::query()
            ->whereKey($message->sequence)
            ->where(
                'reservation_token',
                $message->reservationToken,
            )
            ->whereNull('published_at')
            ->whereNull('dead_lettered_at')
            ->update([
                'available_at' => $availableAt,
                'reservation_token' => null,
                'reserved_until' => null,
                'last_error' => mb_substr($error, 0, 4000),
            ]) === 1;
    }

    /**
     * Move one exhausted reservation into explicit dead-letter state.
     */
    public function markDeadLettered(
        ClaimedOutboxMessage $message,
        CarbonImmutable $deadLetteredAt,
        string $error,
    ): bool {
        return OutboxMessage::query()
            ->whereKey($message->sequence)
            ->where(
                'reservation_token',
                $message->reservationToken,
            )
            ->whereNull('published_at')
            ->whereNull('dead_lettered_at')
            ->update([
                'available_at' => $deadLetteredAt,
                'reservation_token' => null,
                'reserved_until' => null,
                'last_error' => mb_substr($error, 0, 4000),
                'dead_lettered_at' => $deadLetteredAt,
            ]) === 1;
    }
}
