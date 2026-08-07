<?php

declare(strict_types=1);

namespace App\Application\Planning\Notion;

use App\Application\Planning\RoadmapCommandFingerprint;
use App\Application\Planning\RoadmapEligibility;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Integrations\IntegrationProvider;
use App\Models\ExternalTicketMapping;
use App\Models\NotionPublicationSummary;
use App\Models\Roadmap;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/** Retries only mappings whose last failure was classified as safe to retry. */
final readonly class RetryFailedNotionPublication
{
    public function __construct(
        private UpsertNotionTicket $upsert,
        private TransactionManager $transactions,
        private RoadmapEligibility $eligibility,
    ) {}

    /** @param list<int>|null $taskIds */
    public function handle(int $actorUserId, int $organizationId, Roadmap $roadmap, string $idempotencyKey, ?array $taskIds = null, ?string $correlationId = null): NotionPublicationSummary
    {
        $roadmap->loadMissing('project');
        if ($roadmap->project->organization_id !== $organizationId) {
            abort(404);
        }
        if (! $this->eligibility->allowsPublicationOrDevelopment($roadmap)) {
            throw ValidationException::withMessages(['roadmap' => 'The roadmap is not approved and ready for publication.']);
        }
        $tasks = $roadmap->tasks()->with('externalTicketMappings')->get();
        if ($taskIds !== null) {
            $tasks = $tasks->whereIn('id', $taskIds);
        }
        $fingerprint = RoadmapCommandFingerprint::make([
            'operation' => 'notion_retry',
            'roadmap_id' => $roadmap->id,
            'revision' => $roadmap->revision,
            'idempotency_key' => $idempotencyKey,
            'task_ids' => $tasks->pluck('id')->sort()->values()->all(),
        ]);
        $existing = NotionPublicationSummary::query()
            ->where('roadmap_id', $roadmap->id)
            ->where('request_fingerprint', $fingerprint)
            ->whereNotNull('completed_at')
            ->latest('id')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $outcomes = [];
        foreach ($tasks as $task) {
            $mapping = $task->externalTicketMappings->firstWhere('provider', IntegrationProvider::Notion->value);
            if (! $mapping instanceof ExternalTicketMapping || $mapping->state !== 'failed' || ! is_array($mapping->failure_metadata) || ($mapping->failure_metadata['retryable'] ?? false) !== true) {
                continue;
            }
            $this->transactions->run(function () use ($mapping): void {
                $locked = ExternalTicketMapping::query()->lockForUpdate()->findOrFail($mapping->id);
                if ($locked->state !== 'failed' || ! is_array($locked->failure_metadata) || ($locked->failure_metadata['retryable'] ?? false) !== true) {
                    throw ValidationException::withMessages(['notion' => 'The selected ticket is no longer retryable.']);
                }
                $locked->update(['state' => 'pending']);
            });
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
}
