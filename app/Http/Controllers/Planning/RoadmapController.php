<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Application\Planning\MaterializeRoadmap;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlanningExecutionDiagnostic;
use App\Models\Project;
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
            ],
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
                ];
            })->all(),
            'reverseTraceability' => $this->reverseTraceability($roadmap),
            'edits' => $roadmap->edits->map(fn ($edit): array => ['contentVersion' => $edit->content_version, 'actorName' => $edit->actor->name, 'createdAt' => $edit->created_at->toISOString()])->all(),
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
