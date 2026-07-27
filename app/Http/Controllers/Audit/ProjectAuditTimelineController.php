<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Application\Audit\Data\AuditTimelineCriteria;
use App\Application\Audit\ListAuditTimeline;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ProjectAuditTimelineRequest;
use App\Models\Artifact;
use App\Models\AuditEvent;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the read-only project execution inspector and audit timeline.
 */
final class ProjectAuditTimelineController extends Controller
{
    /**
     * Display project executions and authoritative audit history.
     */
    public function __invoke(
        ProjectAuditTimelineRequest $request,
        Organization $organization,
        Project $project,
        ListAuditTimeline $listAuditTimeline,
    ): Response {
        $criteria = $request->criteria(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        $timeline = $listAuditTimeline->handle($criteria);

        $executions = Execution::query()
            ->forProject($project->id)
            ->with('attempts')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        $selectedExecution = $this->selectedExecution(
            projectId: $project->id,
            executionId: $criteria->executionId,
            recentExecutions: $executions,
        );

        if (
            $selectedExecution instanceof Execution
            && ! $executions->contains(
                fn (Execution $execution): bool => $execution->id
                    === $selectedExecution->id,
            )
        ) {
            $executions->prepend($selectedExecution);
        }

        $artifactCounts = $this->artifactCounts(
            projectId: $project->id,
            executionIds: $executions
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id),
        );

        $selectedArtifacts = $selectedExecution instanceof Execution
            ? Artifact::query()
                ->forProject($project->id)
                ->forExecution($selectedExecution->id)
                ->withCount('evidence')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
            : new EloquentCollection;

        return Inertia::render('projects/audit', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => [
                    'value' => $project->status->value,
                    'label' => Str::headline($project->status->value),
                ],
            ],
            'auditUrl' => route(
                'organizations.projects.audit.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            'projectUrl' => route(
                'organizations.projects.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            'filters' => $this->serializeCriteria($criteria),
            'filterOptions' => [
                'eventTypes' => $this->eventTypeOptions(),
                'subjectTypes' => $this->subjectTypeOptions(),
            ],
            'executions' => $executions
                ->map(
                    fn (Execution $execution): array => $this
                        ->serializeExecutionSummary(
                            execution: $execution,
                            artifactCount: (int) $artifactCounts->get(
                                $execution->id,
                                0,
                            ),
                        ),
                )
                ->values()
                ->all(),
            'selectedExecution' => $selectedExecution instanceof Execution
                ? $this->serializeSelectedExecution(
                    execution: $selectedExecution,
                    artifacts: $selectedArtifacts,
                )
                : null,
            'timeline' => [
                'data' => array_values(array_map(
                    fn (AuditEvent $event): array => $this
                        ->serializeAuditEvent($event),
                    $timeline->items(),
                )),
                'perPage' => $timeline->perPage(),
                'nextCursor' => $timeline->nextCursor()?->encode(),
                'previousCursor' => $timeline
                    ->previousCursor()
                    ?->encode(),
            ],
        ]);
    }

    /**
     * Resolve the explicitly selected execution or the newest execution.
     *
     * Explicit execution identifiers are always queried through the project
     * scope so identifiers from another project cannot be exposed.
     *
     * @param  EloquentCollection<int, Execution>  $recentExecutions
     */
    private function selectedExecution(
        int $projectId,
        ?string $executionId,
        EloquentCollection $recentExecutions,
    ): ?Execution {
        if ($executionId !== null) {
            return Execution::query()
                ->forProject($projectId)
                ->with('attempts')
                ->whereKey($executionId)
                ->firstOrFail();
        }

        $execution = $recentExecutions->first();

        return $execution instanceof Execution
            ? $execution
            : null;
    }

    /**
     * Count immutable artifacts for each displayed execution.
     *
     * @param  Collection<int, string>  $executionIds
     * @return Collection<string, int|string>
     */
    private function artifactCounts(
        int $projectId,
        Collection $executionIds,
    ): Collection {
        if ($executionIds->isEmpty()) {
            return collect();
        }

        return Artifact::query()
            ->forProject($projectId)
            ->whereIn('execution_id', $executionIds->all())
            ->select('execution_id')
            ->selectRaw('COUNT(*) AS artifact_count')
            ->groupBy('execution_id')
            ->pluck('artifact_count', 'execution_id');
    }

    /**
     * Serialize the active timeline filters for the Inertia page.
     *
     * @return array{
     *     execution: string|null,
     *     eventType: string|null,
     *     subjectType: string|null,
     *     subjectId: string|null,
     *     correlationId: string|null,
     *     causationId: string|null,
     *     order: string,
     *     perPage: int
     * }
     */
    private function serializeCriteria(
        AuditTimelineCriteria $criteria,
    ): array {
        return [
            'execution' => $criteria->executionId,
            'eventType' => $criteria->eventType?->value,
            'subjectType' => $criteria->subjectType?->value,
            'subjectId' => $criteria->subjectId,
            'correlationId' => $criteria->correlationId,
            'causationId' => $criteria->causationId,
            'order' => $criteria->order->value,
            'perPage' => $criteria->perPage,
        ];
    }

    /**
     * Serialize one compact execution summary.
     *
     * @return array<string, mixed>
     */
    private function serializeExecutionSummary(
        Execution $execution,
        int $artifactCount,
    ): array {
        $attempts = $execution->attempts;
        $latestAttempt = $attempts->last();

        return [
            'id' => $execution->id,
            'capability' => $execution->capability,
            'logicalRole' => $execution->logical_role,
            'status' => [
                'value' => $execution->status->value,
                'label' => Str::headline($execution->status->value),
            ],
            'requestedReasoning' => $execution
                ->requested_reasoning_level
                ->value,
            'attemptCount' => $attempts->count(),
            'retryCount' => max($attempts->count() - 1, 0),
            'retryLimit' => $execution->retry_limit,
            'artifactCount' => $artifactCount,
            'errorCount' => $attempts
                ->filter(
                    static fn (
                        ExecutionAttempt $attempt,
                    ): bool => $attempt->error_code !== null
                        || $attempt->error_message !== null,
                )
                ->count(),
            'estimatedCost' => $this->sumAttemptCost(
                attempts: $attempts,
                attribute: 'estimated_cost',
            ),
            'actualCost' => $this->sumAttemptCost(
                attempts: $attempts,
                attribute: 'actual_cost',
            ),
            'costCurrency' => $this->costCurrency($attempts),
            'latestProvider' => $latestAttempt instanceof ExecutionAttempt
                ? $latestAttempt->execution_provider
                : null,
            'isSimulated' => $latestAttempt instanceof ExecutionAttempt
                && $latestAttempt->execution_provider === 'simulation',
            'correlationId' => $execution->correlation_id,
            'startedAt' => $execution->started_at?->toIso8601String(),
            'finishedAt' => $execution->finished_at?->toIso8601String(),
            'nextAttemptAt' => $execution
                ->next_attempt_at
                ?->toIso8601String(),
            'createdAt' => $execution->created_at?->toIso8601String(),
        ];
    }

    /**
     * Serialize the selected execution with every attempt and artifact.
     *
     * @param  EloquentCollection<int, Artifact>  $artifacts
     * @return array<string, mixed>
     */
    private function serializeSelectedExecution(
        Execution $execution,
        EloquentCollection $artifacts,
    ): array {
        return [
            ...$this->serializeExecutionSummary(
                execution: $execution,
                artifactCount: $artifacts->count(),
            ),
            'idempotencyKey' => $execution->idempotency_key,
            'timeoutSeconds' => $execution->timeout_seconds,
            'cancellationReason' => $execution->cancellation_reason,
            'cancelRequestedAt' => $execution
                ->cancel_requested_at
                ?->toIso8601String(),
            'cancelledAt' => $execution
                ->cancelled_at
                ?->toIso8601String(),
            'attempts' => $execution->attempts
                ->map(
                    fn (
                        ExecutionAttempt $attempt,
                    ): array => $this->serializeAttempt($attempt),
                )
                ->values()
                ->all(),
            'artifacts' => $artifacts
                ->map(
                    fn (Artifact $artifact): array => $this
                        ->serializeArtifact($artifact),
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Serialize one immutable provider attempt.
     *
     * @return array<string, mixed>
     */
    private function serializeAttempt(
        ExecutionAttempt $attempt,
    ): array {
        return [
            'id' => $attempt->id,
            'attemptNumber' => $attempt->attempt_number,
            'status' => [
                'value' => $attempt->status->value,
                'label' => Str::headline($attempt->status->value),
            ],
            'provider' => $attempt->execution_provider,
            'modelIdentifier' => $attempt->model_identifier,
            'requestedReasoning' => $attempt
                ->requested_reasoning_level
                ->value,
            'effectiveReasoning' => $attempt
                ->effective_reasoning_level
                ->value,
            'reasoningSource' => $attempt->reasoning_resolution_source,
            'reasoningEscalationReason' => $attempt
                ->reasoning_escalation_reason,
            'simulationMode' => $attempt->simulation_mode,
            'simulationSeed' => $attempt->simulation_seed,
            'reportedState' => $attempt->reported_state,
            'observedState' => $attempt->observed_state,
            'actualState' => $attempt->actual_state,
            'confidence' => $attempt->confidence,
            'estimatedCost' => $attempt->estimated_cost,
            'actualCost' => $attempt->actual_cost,
            'costCurrency' => $attempt->cost_currency,
            'error' => $attempt->error_code !== null
                || $attempt->error_message !== null
                ? [
                    'code' => $attempt->error_code,
                    'message' => $attempt->error_message,
                    'retryable' => $attempt->retryable,
                    'retryDelaySeconds' => $attempt
                        ->retry_delay_seconds,
                ]
                : null,
            'deadlineAt' => $attempt->deadline_at?->toIso8601String(),
            'heartbeatAt' => $attempt->heartbeat_at?->toIso8601String(),
            'startedAt' => $attempt->started_at?->toIso8601String(),
            'finishedAt' => $attempt->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Serialize one immutable execution artifact without exposing raw content.
     *
     * @return array<string, mixed>
     */
    private function serializeArtifact(Artifact $artifact): array
    {
        return [
            'id' => $artifact->id,
            'type' => $artifact->artifact_type,
            'name' => $artifact->name,
            'provider' => $artifact->execution_provider,
            'externalReference' => $artifact->external_reference,
            'mediaType' => $artifact->media_type,
            'checksumSha256' => $artifact->checksum_sha256,
            'byteSize' => $artifact->byte_size,
            'simulationMode' => $artifact->simulation_mode,
            'simulationSeed' => $artifact->simulation_seed,
            'assumptions' => $artifact->assumptions,
            'confidence' => $artifact->confidence,
            'evidenceStillRequired' => $artifact
                ->evidence_still_required,
            'evidenceCount' => (int) $artifact->getAttribute(
                'evidence_count',
            ),
            'actualState' => $artifact->actual_state,
            'isSimulated' => $artifact->isSimulated(),
            'createdAt' => $artifact->created_at->toIso8601String(),
        ];
    }

    /**
     * Serialize one authoritative audit event.
     *
     * Raw metadata is deliberately excluded from the page payload so future
     * metadata keys cannot accidentally expose sensitive internal values.
     *
     * @return array<string, mixed>
     */
    private function serializeAuditEvent(AuditEvent $event): array
    {
        return [
            'sequence' => $event->sequence,
            'eventId' => $event->event_id,
            'eventType' => [
                'value' => $event->event_type->value,
                'label' => Str::headline($event->event_type->value),
            ],
            'actor' => [
                'type' => $event->actor_type->value,
                'id' => $event->actor_id,
            ],
            'subject' => [
                'type' => $event->subject_type->value,
                'id' => $event->subject_id,
            ],
            'executionId' => $event->execution_id,
            'correlationId' => $event->correlation_id,
            'causationId' => $event->causation_id,
            'schemaVersion' => $event->schema_version,
            'occurredAt' => $event->occurred_at->toIso8601String(),
        ];
    }

    /**
     * Sum one nullable decimal cost attribute without changing persistence.
     *
     * @param  EloquentCollection<int, ExecutionAttempt>  $attempts
     */
    private function sumAttemptCost(
        EloquentCollection $attempts,
        string $attribute,
    ): ?string {
        $values = $attempts
            ->map(
                static fn (
                    ExecutionAttempt $attempt,
                ): mixed => $attempt->getAttribute($attribute),
            )
            ->filter(
                static fn (mixed $value): bool => $value !== null,
            );

        if ($values->isEmpty()) {
            return null;
        }

        return number_format(
            num: $values->sum(
                static fn (mixed $value): float => (float) $value,
            ),
            decimals: 8,
            decimal_separator: '.',
            thousands_separator: '',
        );
    }

    /**
     * Resolve the first persisted attempt currency.
     *
     * @param  EloquentCollection<int, ExecutionAttempt>  $attempts
     */
    private function costCurrency(
        EloquentCollection $attempts,
    ): ?string {
        foreach ($attempts as $attempt) {
            if (
                is_string($attempt->cost_currency)
                && $attempt->cost_currency !== ''
            ) {
                return $attempt->cost_currency;
            }
        }

        return null;
    }

    /**
     * Return selectable audit event types.
     *
     * @return list<array{value: string, label: string}>
     */
    private function eventTypeOptions(): array
    {
        return array_map(
            static fn (AuditEventType $type): array => [
                'value' => $type->value,
                'label' => Str::headline($type->value),
            ],
            AuditEventType::cases(),
        );
    }

    /**
     * Return selectable audit subject types.
     *
     * @return list<array{value: string, label: string}>
     */
    private function subjectTypeOptions(): array
    {
        return array_map(
            static fn (AuditSubjectType $type): array => [
                'value' => $type->value,
                'label' => Str::headline($type->value),
            ],
            AuditSubjectType::cases(),
        );
    }
}
