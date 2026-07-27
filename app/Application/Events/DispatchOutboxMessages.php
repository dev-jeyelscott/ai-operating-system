<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Application\Events\Contracts\OutboxDispatchStore;
use App\Application\Events\Contracts\OutboxTransport;
use App\Application\Events\Data\ClaimedOutboxMessage;
use App\Application\Security\RedactSensitiveData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Recovers expired reservations and dispatches committed outbox messages.
 */
final readonly class DispatchOutboxMessages
{
    private const RESERVATION_CONFLICT_WARNING =
        'Outbox reservation ownership changed before the dispatch outcome was persisted.';

    /**
     * Create the dispatcher application service.
     */
    public function __construct(
        private OutboxDispatchStore $store,
        private OutboxTransport $transport,
        private RedactSensitiveData $redactor,
    ) {}

    /**
     * Recover expired terminal reservations and dispatch one bounded batch.
     *
     * Queue publication occurs before published_at is written. A crash between
     * those operations may produce a duplicate queued job, but the durable
     * consumer receipt makes that replay safe. Reversing the order could lose
     * an event permanently.
     *
     * @return array{
     *     expired_dead_lettered: int,
     *     claimed: int,
     *     published: int,
     *     failed: int,
     *     dead_lettered: int,
     *     reservation_conflicts: int
     * }
     */
    public function handle(
        int $limit,
        int $leaseSeconds,
        int $maximumAttempts,
        int $baseBackoffSeconds,
        int $maximumBackoffSeconds,
    ): array {
        $this->validateConfiguration(
            limit: $limit,
            leaseSeconds: $leaseSeconds,
            maximumAttempts: $maximumAttempts,
            baseBackoffSeconds: $baseBackoffSeconds,
            maximumBackoffSeconds: $maximumBackoffSeconds,
        );

        $expiredDeadLettered =
            $this->store->deadLetterExpiredExhaustedReservations(
                maximumAttempts: $maximumAttempts,
                deadLetteredAt: CarbonImmutable::now(),
            );

        $messages = $this->store->claim(
            limit: $limit,
            leaseSeconds: $leaseSeconds,
            maximumAttempts: $maximumAttempts,
        );

        $published = 0;
        $failed = 0;
        $deadLettered = 0;
        $reservationConflicts = 0;

        foreach ($messages as $message) {
            try {
                $this->transport->publish($message->eventId);
            } catch (Throwable $exception) {
                /*
                 * The transport genuinely failed, regardless of whether this
                 * dispatcher still owns the reservation afterward.
                 */
                $failed++;

                $failedAt = CarbonImmutable::now();
                $error = $this->redactor->infrastructureError(
                    errorCode: 'outbox.transport_failed',
                    exception: $exception,
                );

                if (
                    $message->dispatchAttempt
                    >= $maximumAttempts
                ) {
                    if (! $this->store->markDeadLettered(
                        message: $message,
                        deadLetteredAt: $failedAt,
                        error: $error,
                    )) {
                        $reservationConflicts++;
                        $this->recordReservationConflict(
                            message: $message,
                            operation: 'mark_dead_lettered',
                        );

                        continue;
                    }

                    $deadLettered++;

                    continue;
                }

                if (! $this->store->release(
                    message: $message,
                    availableAt: $failedAt->addSeconds(
                        $this->backoffSeconds(
                            attempt: $message->dispatchAttempt,
                            baseSeconds: $baseBackoffSeconds,
                            maximumSeconds: $maximumBackoffSeconds,
                        ),
                    ),
                    error: $error,
                )) {
                    $reservationConflicts++;
                    $this->recordReservationConflict(
                        message: $message,
                        operation: 'release',
                    );
                }

                continue;
            }

            /*
             * Publication succeeded, but the reservation may have expired and
             * been claimed or finalized by another dispatcher before this write.
             */
            if (! $this->store->markPublished($message)) {
                $reservationConflicts++;
                $this->recordReservationConflict(
                    message: $message,
                    operation: 'mark_published',
                );

                continue;
            }

            $published++;
        }

        return [
            'expired_dead_lettered' => $expiredDeadLettered,
            'claimed' => count($messages),
            'published' => $published,
            'failed' => $failed,
            'dead_lettered' => $deadLettered,
            'reservation_conflicts' => $reservationConflicts,
        ];
    }

    /**
     * Record a stable warning without exposing reservation-token values.
     */
    private function recordReservationConflict(
        ClaimedOutboxMessage $message,
        string $operation,
    ): void {
        Log::warning(
            self::RESERVATION_CONFLICT_WARNING,
            [
                'operation' => $operation,
                'outbox_sequence' => $message->sequence,
                'event_id' => $message->eventId,
                'dispatch_attempt' => $message->dispatchAttempt,
            ],
        );
    }

    /**
     * Calculate bounded exponential retry delay with a small jitter.
     */
    private function backoffSeconds(
        int $attempt,
        int $baseSeconds,
        int $maximumSeconds,
    ): int {
        $exponent = min(
            max(0, $attempt - 1),
            10,
        );

        $exponential = min(
            $maximumSeconds,
            $baseSeconds * (2 ** $exponent),
        );

        $jitter = random_int(
            0,
            max(1, intdiv($baseSeconds, 2)),
        );

        return min(
            $maximumSeconds,
            $exponential + $jitter,
        );
    }

    /**
     * Reject invalid runtime configuration before recovering database rows.
     */
    private function validateConfiguration(
        int $limit,
        int $leaseSeconds,
        int $maximumAttempts,
        int $baseBackoffSeconds,
        int $maximumBackoffSeconds,
    ): void {
        foreach (
            [
                'limit' => $limit,
                'lease seconds' => $leaseSeconds,
                'maximum attempts' => $maximumAttempts,
                'base backoff seconds' => $baseBackoffSeconds,
                'maximum backoff seconds' => $maximumBackoffSeconds,
            ] as $name => $value
        ) {
            if ($value < 1) {
                throw new InvalidArgumentException(
                    "The outbox {$name} must be positive.",
                );
            }
        }

        if ($baseBackoffSeconds > $maximumBackoffSeconds) {
            throw new InvalidArgumentException(
                'The outbox base backoff cannot exceed its maximum.',
            );
        }
    }
}
