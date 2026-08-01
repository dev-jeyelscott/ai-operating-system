<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Planning\Notion\RetryFailedNotionPublication;
use App\Models\Roadmap;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Durable, idempotent retry boundary for retryable Notion task failures.
 */
final class RetryFailedNotionPublicationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Include middleware releases while retaining a bounded attempt limit.
     */
    public int $tries = 10;

    /**
     * Stop repeated infrastructure exceptions from running indefinitely.
     */
    public int $maxExceptions = 3;

    /**
     * Preserve the existing queue execution limit.
     */
    public int $timeout = 60;

    /**
     * Retain the idempotency-specific unique dispatch lock for 15 minutes.
     */
    public int $uniqueFor = 900;

    /**
     * Create and route the retry job to the integrations queue.
     *
     * @param  list<int>|null  $taskIds
     */
    public function __construct(
        public readonly int $actorUserId,
        public readonly int $organizationId,
        public readonly int $roadmapId,
        public readonly string $idempotencyKey,
        public readonly ?array $taskIds,
        public readonly ?string $correlationId,
    ) {
        $this->onConnection(
            $this->queueConnection(),
        );

        $this->onQueue(
            $this->queueName(),
        );
    }

    /**
     * Return the retry-request-specific Laravel unique-job key.
     */
    public function uniqueId(): string
    {
        return implode(':', [
            'notion-retry',
            $this->roadmapId,
            hash('sha256', $this->idempotencyKey),
        ]);
    }

    /**
     * Apply provider backpressure and share the roadmap publication lock.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new RateLimitedWithRedis(
                'notion-publication',
            ),

            (new WithoutOverlapping(
                $this->roadmapOverlapKey(),
            ))
                ->shared()
                ->releaseAfter(
                    $this->overlapReleaseSeconds(),
                )
                ->expireAfter(
                    $this->overlapExpireSeconds(),
                ),
        ];
    }

    /**
     * Return bounded queue retry delays in seconds.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [
            5,
            30,
            120,
            300,
        ];
    }

    /**
     * Retry only previously classified retryable task mappings.
     */
    public function handle(
        RetryFailedNotionPublication $retry,
    ): void {
        $roadmap = Roadmap::query()
            ->findOrFail($this->roadmapId);

        $retry->handle(
            actorUserId: $this->actorUserId,
            organizationId: $this->organizationId,
            roadmap: $roadmap,
            idempotencyKey: $this->idempotencyKey,
            taskIds: $this->taskIds,
            correlationId: $this->correlationId,
        );
    }

    /**
     * Add provider and tenant metadata to Horizon without exposing secrets.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'integration',
            'provider:notion',
            'organization:'.$this->organizationId,
            'roadmap:'.$this->roadmapId,
            'operation:retry',
        ];
    }

    /**
     * Share one overlap lock with publication and retry job classes.
     */
    private function roadmapOverlapKey(): string
    {
        return 'notion-roadmap:'.$this->roadmapId;
    }

    /**
     * Resolve the configured queue connection safely.
     */
    private function queueConnection(): string
    {
        $connection = config(
            'integration-resilience.notion.queue.connection',
            'redis',
        );

        return is_string($connection)
            && trim($connection) !== ''
                ? trim($connection)
                : 'redis';
    }

    /**
     * Resolve the dedicated integration queue safely.
     */
    private function queueName(): string
    {
        $queue = config(
            'integration-resilience.notion.queue.name',
            'integrations',
        );

        return is_string($queue)
            && trim($queue) !== ''
                ? trim($queue)
                : 'integrations';
    }

    /**
     * Return how long an overlapping job waits before retrying.
     */
    private function overlapReleaseSeconds(): int
    {
        return max(
            1,
            (int) config(
                'integration-resilience.notion.queue'
                .'.overlap_release_seconds',
                15,
            ),
        );
    }

    /**
     * Return the abandoned overlap-lock recovery duration.
     */
    private function overlapExpireSeconds(): int
    {
        return max(
            $this->timeout + 1,
            (int) config(
                'integration-resilience.notion.queue'
                .'.overlap_expire_seconds',
                90,
            ),
        );
    }
}
