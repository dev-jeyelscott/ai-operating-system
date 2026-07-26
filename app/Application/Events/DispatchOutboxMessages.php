<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Application\Events\Contracts\OutboxDispatchStore;
use App\Application\Events\Contracts\OutboxTransport;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Claims committed outbox messages and queues them for consumer processing.
 */
final readonly class DispatchOutboxMessages
{
    /**
     * Create the dispatcher application service.
     */
    public function __construct(
        private OutboxDispatchStore $store,
        private OutboxTransport $transport,
    ) {}

    /**
     * Dispatch one bounded batch.
     *
     * Queue publication occurs before published_at is written. A crash between
     * those operations may produce a duplicate queued job, but the durable
     * consumer receipt makes that replay safe. Reversing the order could lose
     * an event permanently.
     *
     * @return array{
     *     claimed: int,
     *     published: int,
     *     failed: int
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

        $messages = $this->store->claim(
            limit: $limit,
            leaseSeconds: $leaseSeconds,
            maximumAttempts: $maximumAttempts,
        );

        $published = 0;
        $failed = 0;

        foreach ($messages as $message) {
            try {
                $this->transport->publish($message->eventId);

                if (! $this->store->markPublished($message)) {
                    throw new RuntimeException(
                        'The outbox reservation was lost before publication '
                            .'could be recorded.',
                    );
                }

                $published++;
            } catch (Throwable $exception) {
                $failed++;

                $this->store->release(
                    message: $message,
                    availableAt: CarbonImmutable::now()->addSeconds(
                        $this->backoffSeconds(
                            attempt: $message->dispatchAttempt,
                            baseSeconds: $baseBackoffSeconds,
                            maximumSeconds: $maximumBackoffSeconds,
                        ),
                    ),
                    error: sprintf(
                        '%s: %s',
                        $exception::class,
                        $exception->getMessage(),
                    ),
                );
            }
        }

        return [
            'claimed' => count($messages),
            'published' => $published,
            'failed' => $failed,
        ];
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
     * Reject invalid runtime configuration before claiming database rows.
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
