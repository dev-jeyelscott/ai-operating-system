<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Planning\Notion\RetryFailedNotionPublication;
use App\Models\Roadmap;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Durable, idempotent retry boundary for retryable Notion task failures. */
final class RetryFailedNotionPublicationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    /** @param list<int>|null $taskIds */
    public function __construct(
        public readonly int $actorUserId,
        public readonly int $organizationId,
        public readonly int $roadmapId,
        public readonly string $idempotencyKey,
        public readonly ?array $taskIds,
        public readonly ?string $correlationId,
    ) {}

    public function uniqueId(): string
    {
        return implode(':', ['notion-retry', $this->roadmapId, hash('sha256', $this->idempotencyKey)]);
    }

    public function handle(RetryFailedNotionPublication $retry): void
    {
        $roadmap = Roadmap::query()->findOrFail($this->roadmapId);
        $retry->handle($this->actorUserId, $this->organizationId, $roadmap, $this->idempotencyKey, $this->taskIds, $this->correlationId);
    }
}
