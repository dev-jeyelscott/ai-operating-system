<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Planning\Notion\PublishRoadmapToNotion;
use App\Models\Roadmap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Durable, idempotent publication boundary for one approved roadmap request. */
final class PublishRoadmapToNotionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $actorUserId,
        public readonly int $organizationId,
        public readonly int $roadmapId,
        public readonly string $idempotencyKey,
        public readonly ?string $correlationId,
    ) {}

    public function uniqueId(): string
    {
        return implode(':', ['notion-publication', $this->roadmapId, hash('sha256', $this->idempotencyKey)]);
    }

    public function handle(PublishRoadmapToNotion $publish): void
    {
        $roadmap = Roadmap::query()->findOrFail($this->roadmapId);
        $publish->handle($this->actorUserId, $this->organizationId, $roadmap, $this->idempotencyKey, $this->correlationId);
    }
}
