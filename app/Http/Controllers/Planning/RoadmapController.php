<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Application\Planning\MaterializeRoadmap;
use App\Domain\Integrations\NotionConnectionFailureCode;
use App\Http\Controllers\Controller;
use App\Models\NotionPublicationSummary;
use App\Models\NotionReconciliationConflict;
use App\Models\Organization;
use App\Models\PlanningExecutionDiagnostic;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\Roadmap;
use App\Models\RoadmapPhase;
use App\Models\RoadmapTask;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class RoadmapController extends Controller
{
    public function index(Request $request, Organization $organization, Project $project, MaterializeRoadmap $materializer): Response
    {
        $roadmap = Roadmap::query()->where('project_id', $project->id)->orderByDesc('revision')->first();

        return $this->render($request, $organization, $project, $roadmap, $materializer);
    }

    public function show(Request $request, Organization $organization, Project $project, Roadmap $roadmap, MaterializeRoadmap $materializer): Response
    {
        abort_unless($roadmap->project_id === $project->id, 404);

        return $this->render($request, $organization, $project, $roadmap, $materializer);
    }

    public function phase(Request $request, Organization $organization, Project $project, Roadmap $roadmap, RoadmapPhase $phase, MaterializeRoadmap $materializer): Response
    {
        abort_unless($roadmap->project_id === $project->id && $phase->roadmap_id === $roadmap->id, 404);

        return $this->render($request, $organization, $project, $roadmap, $materializer, phaseId: $phase->id);
    }

    public function task(Request $request, Organization $organization, Project $project, Roadmap $roadmap, RoadmapTask $task, MaterializeRoadmap $materializer): Response
    {
        abort_unless($roadmap->project_id === $project->id && $task->roadmap_id === $roadmap->id, 404);

        return $this->render($request, $organization, $project, $roadmap, $materializer, taskId: $task->id);
    }

    private function render(Request $request, Organization $organization, Project $project, ?Roadmap $roadmap, MaterializeRoadmap $materializer, ?int $phaseId = null, ?int $taskId = null): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $revisions = Roadmap::query()
            ->where('project_id', $project->id)
            ->orderByDesc('revision')
            ->get(['id', 'revision', 'content_version', 'status', 'readiness', 'generated_at']);

        if ($roadmap !== null) {
            $roadmap->load([
                'approval',
                'edits.actor',
                'phases.milestones',
                'tasks.phase',
                'tasks.milestone',
                'tasks.dependencies.dependsOn',
                'tasks.traceabilityLinks.documentVersion.document',
                'tasks.externalTicketMappings',
                'traceabilityLinks.documentVersion.document',
            ]);
        }

        return Inertia::render('projects/roadmaps/show', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
            'project' => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug, 'status' => $project->status->value],
            'projectUrl' => route('organizations.projects.show', compact('organization', 'project')),
            'revisions' => $revisions->map(fn (Roadmap $revision): array => [
                'id' => $revision->id,
                'revision' => $revision->revision,
                'contentVersion' => $revision->content_version,
                'status' => $revision->status,
                'readiness' => $revision->readiness,
                'generatedAt' => $revision->generated_at->toISOString(),
            ])->all(),
            'roadmap' => $roadmap === null ? null : $this->serializeRoadmap($roadmap, $materializer),
            'diagnostics' => Inertia::defer(fn (): array => PlanningExecutionDiagnostic::query()
                ->whereHas('execution', fn ($query) => $query->where('project_id', $project->id))
                ->latest('created_at')
                ->limit(10)
                ->get(['code', 'category', 'message', 'created_at'])
                ->map(fn (PlanningExecutionDiagnostic $diagnostic): array => [
                    'code' => $diagnostic->code,
                    'category' => $diagnostic->category,
                    'message' => $diagnostic->message,
                    'createdAt' => $diagnostic->created_at->toISOString(),
                ])->all(), 'roadmap-inspection'),
            'selectedPhaseId' => $phaseId,
            'selectedTaskId' => $taskId,
            'permissions' => [
                'edit' => $actor->can('update', $project),
                'decide' => $actor->can('approve', $project),
                'regenerate' => $actor->can('approve', $project),
                'publish' => $actor->can('approve', $project),
            ],
            'notionPublication' => $roadmap === null ? null : $this->serializeNotionPublication($project, $roadmap),
            'notionConflicts' => $roadmap === null ? [] : NotionReconciliationConflict::query()
                ->where('project_id', $project->id)
                ->whereIn('state', ['open', 'republish_queued', 'republish_failed'])
                ->whereHas('mapping.task', fn ($query) => $query->where('roadmap_id', $roadmap->id))
                ->latest('id')
                ->get(['id', 'external_ticket_mapping_id', 'current_fingerprint', 'external_fingerprint', 'state', 'decision', 'decision_reason'])
                ->map(fn (NotionReconciliationConflict $conflict): array => [
                    'id' => $conflict->id,
                    'mappingId' => $conflict->external_ticket_mapping_id,
                    'currentFingerprint' => $conflict->current_fingerprint,
                    'externalFingerprint' => $conflict->external_fingerprint,
                    'state' => $conflict->state,
                    'decision' => $conflict->decision,
                    'decisionReason' => $conflict->decision_reason,
                ])->all(),
            'actionIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeRoadmap(Roadmap $roadmap, MaterializeRoadmap $materializer): array
    {
        $candidate = $materializer->handle($roadmap);
        $tasksByStableId = $this->tasksByStableId($candidate);

        return [
            'id' => $roadmap->id,
            'revision' => $roadmap->revision,
            'contentVersion' => $roadmap->content_version,
            'candidateFingerprint' => $roadmap->candidate_fingerprint,
            'outputFingerprint' => $roadmap->output_fingerprint,
            'status' => $roadmap->status,
            'isLatest' => Roadmap::query()->where('project_id', $roadmap->project_id)->max('revision') === $roadmap->revision,
            'readiness' => $roadmap->readiness,
            'readinessReasons' => $roadmap->readiness_reasons,
            'goal' => $candidate['goal'] ?? $roadmap->goal,
            'scope' => $candidate['scope'] ?? $roadmap->scope,
            'assumptions' => $candidate['assumptions'] ?? $roadmap->assumptions,
            'constraints' => $candidate['constraints'] ?? $roadmap->constraints,
            'definitionOfDone' => $candidate['definition_of_done'] ?? $roadmap->definition_of_done,
            'requiredApprovals' => $roadmap->required_approvals,
            'documentSummary' => $roadmap->document_summary,
            'documentInventory' => $roadmap->document_inventory,
            'architectureConcerns' => $roadmap->architecture_concerns,
            'securityConcerns' => $roadmap->security_concerns,
            'criticalPath' => ($roadmap->derived_graph ?? [])['critical_path'] ?? [],
            'comparison' => [
                'generatedGoal' => $roadmap->generated_snapshot['goal'] ?? null,
                'candidateGoal' => $candidate['goal'] ?? null,
                'approvedGoal' => $roadmap->approved_snapshot['goal'] ?? null,
                'generatedFingerprint' => $roadmap->output_fingerprint,
                'candidateFingerprint' => $roadmap->candidate_fingerprint,
                'approvedFingerprint' => $roadmap->approved_fingerprint,
            ],
            'approval' => $roadmap->approval === null ? null : [
                'id' => $roadmap->approval->id,
                'status' => $roadmap->approval->status->value,
                'reason' => $roadmap->approval->decision_reason,
            ],
            'phases' => $roadmap->phases->map(fn (RoadmapPhase $phase): array => [
                'id' => $phase->id,
                'stableId' => $phase->stable_id,
                'name' => $phase->name,
                'milestones' => $phase->milestones->map(fn ($milestone): array => ['id' => $milestone->id, 'stableId' => $milestone->stable_id, 'name' => $milestone->name])->all(),
            ])->all(),
            'tasks' => $roadmap->tasks->map(function (RoadmapTask $task) use ($tasksByStableId): array {
                $candidate = $tasksByStableId[$task->stable_id] ?? [];

                return [
                    'id' => $task->id,
                    'stableId' => $task->stable_id,
                    'title' => $candidate['title'] ?? $task->title,
                    'objective' => $candidate['objective'] ?? $task->objective,
                    'phaseId' => $task->roadmap_phase_id,
                    'phaseName' => $task->phase?->name,
                    'milestoneName' => $task->milestone?->name,
                    'ticketType' => $task->ticket_type,
                    'scope' => $candidate['scope'] ?? $task->scope,
                    'acceptanceCriteria' => $candidate['acceptance_criteria'] ?? $task->acceptance_criteria,
                    'evidenceRequirements' => $candidate['evidence_requirements'] ?? $task->evidence_requirements,
                    'priority' => $candidate['priority'] ?? $task->priority,
                    'risk' => $candidate['risk'] ?? $task->risk,
                    'reasoningLevel' => $task->reasoning_level,
                    'reasoning' => $candidate['reasoning'] ?? $task->reasoning,
                    'logicalAgent' => $candidate['logical_agent'] ?? $task->logical_agent,
                    'estimatedComplexity' => $candidate['estimated_complexity'] ?? $task->estimated_complexity,
                    'humanApprovalRequired' => $candidate['human_approval_required'] ?? $task->human_approval_required,
                    'criticalPathRank' => $task->critical_path_rank,
                    'criticalPathPosition' => $task->critical_path_position,
                    'isCriticalPath' => $task->is_critical_path,
                    'dependencies' => $task->dependencies->map(fn ($dependency): array => ['id' => $dependency->dependsOn->id, 'stableId' => $dependency->dependsOn->stable_id, 'title' => $dependency->dependsOn->title])->all(),
                    'traceability' => $task->traceabilityLinks->map(fn ($link): array => [
                        'criterionStableId' => $link->criterion_stable_id,
                        'documentId' => $link->document_id,
                        'documentVersionId' => $link->document_version_id,
                        'documentVersion' => $link->document_version,
                        'checksumSha256' => $link->checksum_sha256,
                        'documentName' => $link->documentVersion->document->title,
                    ])->all(),
                    'notionMapping' => ($mapping = $task->externalTicketMappings->firstWhere('provider', 'notion')) === null ? null : [
                        'externalKey' => $mapping->external_key,
                        'mappingId' => $mapping->id,
                        'pageUrl' => $mapping->page_url,
                        'state' => $mapping->state,
                        'reconciliationState' => $mapping->reconciliation_state,
                        'retryable' => ($mapping->failure_metadata['retryable'] ?? false) === true,
                    ],
                ];
            })->all(),
            'reverseTraceability' => $this->reverseTraceability($roadmap),
            'edits' => $roadmap->edits->map(fn ($edit): array => ['contentVersion' => $edit->content_version, 'actorName' => $edit->actor->name, 'createdAt' => $edit->created_at->toISOString()])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeNotionPublication(Project $project, Roadmap $roadmap): array
    {
        $integration = ProjectIntegration::query()
            ->forOrganization($project->organization_id)
            ->forProject($project->id)
            ->where('provider', 'notion')
            ->first();
        $summary = NotionPublicationSummary::query()
            ->where('roadmap_id', $roadmap->id)
            ->latest('id')
            ->first();

        return [
            'readiness' => $integration?->connection_status->value === 'connected'
                && $integration->data_source_id !== null,
            'schemaReadiness' => $integration === null
                ? 'not_configured'
                : ($integration->last_failure_code === NotionConnectionFailureCode::SchemaIncompatible
                    ? 'incompatible'
                    : ($integration->connection_status->value === 'connected' ? 'ready' : 'unverified')),
            'dataSourceName' => $integration?->data_source_name,
            'dataSourceId' => $integration?->data_source_id,
            'summary' => $summary === null ? null : [
                'createdCount' => $summary->created_count,
                'updatedCount' => $summary->updated_count,
                'skippedCount' => $summary->skipped_count,
                'failedCount' => $summary->failed_count,
                'conflictedCount' => $summary->conflicted_count,
                'completedAt' => $summary->completed_at,
                'diagnostics' => collect(is_array($summary->outcomes) ? $summary->outcomes : [])
                    ->filter(fn (array $outcome): bool => in_array($outcome['outcome'] ?? null, ['failed', 'conflicted', 'blocked'], true))
                    ->map(fn (array $outcome): string => is_string($outcome['message'] ?? null) && $outcome['message'] !== '' ? $outcome['message'] : 'Notion publication requires attention.')
                    ->unique()
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, array<string, mixed>>
     */
    private function tasksByStableId(array $candidate): array
    {
        $roadmap = $candidate['roadmap'] ?? null;
        $tasks = is_array($roadmap) ? ($roadmap['tasks'] ?? null) : null;
        if (! is_array($tasks)) {
            return [];
        }

        $byStableId = [];
        foreach ($tasks as $task) {
            if (! is_array($task) || ! isset($task['stable_id']) || ! is_string($task['stable_id'])) {
                continue;
            }
            $byStableId[$task['stable_id']] = $task;
        }

        return $byStableId;
    }

    /** @return list<array<string, mixed>> */
    private function reverseTraceability(Roadmap $roadmap): array
    {
        $matrix = [];
        foreach ($roadmap->traceabilityLinks as $link) {
            $key = (string) $link->document_version_id;
            if (! isset($matrix[$key])) {
                $matrix[$key] = [
                    'documentVersionId' => $link->document_version_id,
                    'documentName' => $link->documentVersion->document->title,
                    'documentVersion' => $link->document_version,
                    'tasks' => [],
                ];
            }
            $matrix[$key]['tasks'][] = [
                'taskId' => $link->roadmap_task_id,
                'criterionStableId' => $link->criterion_stable_id,
            ];
        }

        return array_values($matrix);
    }
}
