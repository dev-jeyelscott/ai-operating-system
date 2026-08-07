<?php

declare(strict_types=1);

namespace App\Application\Planning\Notion;

use App\Application\Planning\RoadmapCommandFingerprint;
use App\Application\Planning\RoadmapEligibility;
use App\Domain\Integrations\IntegrationProvider;
use App\Models\ExternalTicketMapping;
use App\Models\NotionPublicationSummary;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/** Coordinates deterministic dependency-aware task publication. */
final readonly class PublishRoadmapToNotion
{
    public function __construct(
        private RoadmapEligibility $eligibility,
        private UpsertNotionTicket $upsert,
    ) {}

    public function handle(int $actorUserId, int $organizationId, Roadmap $roadmap, string $idempotencyKey, ?string $correlationId = null): NotionPublicationSummary
    {
        $roadmap->loadMissing('project');
        if ($roadmap->project->organization_id !== $organizationId) {
            abort(404);
        }
        if (! $this->eligibility->allowsPublicationOrDevelopment($roadmap)) {
            throw ValidationException::withMessages(['roadmap' => 'The roadmap is not approved and ready for publication.']);
        }
        $fingerprint = RoadmapCommandFingerprint::make(['roadmap_id' => $roadmap->id, 'revision' => $roadmap->revision, 'approved_fingerprint' => $roadmap->approved_fingerprint, 'idempotency_key' => $idempotencyKey]);
        $existing = NotionPublicationSummary::query()->where('roadmap_id', $roadmap->id)->where('request_fingerprint', $fingerprint)->whereNotNull('completed_at')->latest('id')->first();
        if ($existing !== null) {
            return $existing;
        }

        $outcomes = [];
        foreach ($this->orderedTasks($roadmap) as $task) {
            if ($this->dependenciesAreBlocked($task)) {
                $outcomes[] = ['task_id' => $task->id, 'outcome' => 'blocked', 'message' => 'A hard dependency has no successful external mapping.'];

                continue;
            }
            $result = $this->upsert->handle($actorUserId, $organizationId, $task, $correlationId);
            $outcomes[] = ['task_id' => $task->id, 'mapping_id' => $result->mappingId, 'outcome' => $result->outcome, 'page_url' => $result->pageUrl, 'message' => $result->message];
        }
        $counts = collect($outcomes)->countBy('outcome');

        return $this->persistSummary([
            'organization_id' => $organizationId,
            'project_id' => $roadmap->project_id,
            'roadmap_id' => $roadmap->id,
            'request_fingerprint' => $fingerprint,
            'correlation_id' => $correlationId,
            'outcomes' => $outcomes,
            'created_count' => $counts->get('created', 0),
            'updated_count' => $counts->get('updated', 0),
            'skipped_count' => $counts->get('skipped', 0) + $counts->get('blocked', 0),
            'failed_count' => $counts->get('failed', 0),
            'conflicted_count' => $counts->get('conflicted', 0),
            'completed_at' => now(),
        ]);
    }

    private function dependenciesAreBlocked(RoadmapTask $task): bool
    {
        return $task->dependencies->contains(function ($dependency): bool {
            $mapping = ExternalTicketMapping::query()->where('roadmap_task_id', $dependency->depends_on_task_id)->where('provider', IntegrationProvider::Notion->value)->first();

            return $mapping === null || $mapping->state !== 'synchronized' || $mapping->page_id === null;
        });
    }

    /** @param array<string, mixed> $attributes */
    private function persistSummary(array $attributes): NotionPublicationSummary
    {
        try {
            return NotionPublicationSummary::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            return NotionPublicationSummary::query()
                ->where('roadmap_id', $attributes['roadmap_id'])
                ->where('request_fingerprint', $attributes['request_fingerprint'])
                ->whereNotNull('completed_at')
                ->latest('id')
                ->firstOrFail();
        }
    }

    /** @return list<RoadmapTask> */
    private function orderedTasks(Roadmap $roadmap): array
    {
        $tasks = $roadmap->tasks()->with('dependencies')->get()->keyBy('id');
        $ordered = [];
        $visiting = [];
        $visited = [];
        $visit = function (RoadmapTask $task) use (&$visit, &$ordered, &$visiting, &$visited, $tasks): void {
            if (isset($visited[$task->id])) {
                return;
            }
            if (isset($visiting[$task->id])) {
                throw ValidationException::withMessages(['roadmap' => 'The roadmap dependencies contain a cycle.']);
            }
            $visiting[$task->id] = true;
            foreach ($task->dependencies->sortBy('depends_on_task_id') as $dependency) {
                $dependencyTask = $tasks->get($dependency->depends_on_task_id);
                if ($dependencyTask instanceof RoadmapTask) {
                    $visit($dependencyTask);
                }
            }
            unset($visiting[$task->id]);
            $visited[$task->id] = true;
            $ordered[] = $task;
        };
        foreach ($tasks->sortBy('position') as $task) {
            $visit($task);
        }

        return $ordered;
    }
}
