<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Executions\Data\ProviderSelection;
use App\Application\Planning\Data\PlanningExecutionRequest;
use App\Application\Planning\Data\PlanningExecutionResult;
use App\Models\Execution;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapMilestone;
use App\Models\RoadmapPhase;
use App\Models\RoadmapTask;
use App\Models\RoadmapTraceabilityLink;
use App\Models\TaskDependency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class PersistRoadmap
{
    public function __construct(
        private RoadmapGraph $graph,
        private RoadmapReadinessEvaluator $readiness,
        private RecordRoadmapLifecycleEvent $events,
    ) {}

    public function handle(Execution $execution, PlanningExecutionRequest $request, PlanningExecutionResult $result, ProviderSelection $selection): Roadmap
    {
        if ($execution->project_id !== $request->projectId || $execution->project_context_snapshot_id !== $request->contextSnapshotId) {
            throw new LogicException('Planning execution and immutable request ownership are inconsistent.');
        }

        $graph = $this->graph->analyze($result);
        $readiness = $this->readiness->evaluate($result);
        $generatedSnapshot = $result->toArray();
        $inputFingerprint = RoadmapCommandFingerprint::make($request->toArray());
        $outputFingerprint = RoadmapCommandFingerprint::make($generatedSnapshot);

        $persist = fn (): Roadmap => DB::transaction(function () use ($execution, $request, $result, $selection, $graph, $readiness, $generatedSnapshot, $inputFingerprint, $outputFingerprint): Roadmap {
            Project::query()->whereKey($execution->project_id)->lockForUpdate()->firstOrFail();

            $existing = Roadmap::query()
                ->where('planning_execution_id', $execution->id)
                ->where('input_fingerprint', $inputFingerprint)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $parent = Roadmap::query()
                ->where('project_id', $execution->project_id)
                ->orderByDesc('revision')
                ->first();
            $revision = $parent === null ? 1 : $parent->revision + 1;
            $status = $readiness['decision'] === 'blocked' ? 'blocked' : 'generated';

            $roadmap = Roadmap::query()->create([
                'project_id' => $execution->project_id,
                'planning_execution_id' => $execution->id,
                'project_context_snapshot_id' => $request->contextSnapshotId,
                'parent_roadmap_id' => $parent?->id,
                'schema_version' => $result->schemaVersion,
                'revision' => $revision,
                'content_version' => 1,
                'provider_id' => $selection->providerId,
                'scenario' => $selection->simulation
                    ? $request->scenario
                    : null,
                'seed' => $selection->simulation
                    ? $request->seed
                    : null,
                'input_fingerprint' => $inputFingerprint,
                'output_fingerprint' => $outputFingerprint,
                'candidate_fingerprint' => $outputFingerprint,
                'status' => $status,
                'readiness' => $readiness['decision'],
                'goal' => $result->goal,
                'scope' => $result->scope,
                'assumptions' => $result->assumptions,
                'constraints' => $result->constraints,
                'definition_of_done' => $result->definitionOfDone,
                'required_approvals' => $result->requiredApprovals,
                'document_inventory' => array_map(static fn ($document): array => $document->toArray(), $result->documentInventory),
                'document_summary' => $result->documentSummary,
                'architecture_concerns' => $result->architectureConcerns,
                'security_concerns' => $result->securityConcerns,
                'readiness_reasons' => $readiness['reasons'],
                'metadata' => [
                    'provider_protocol_version' => $selection
                        ->protocolVersion,
                    'provider_sandbox_profile' => $selection
                        ->sandboxProfile,
                    'effective_capability' => $selection
                        ->effectiveCapability
                        ->value,
                    'provider_selection_source' => $selection
                        ->selectionSource,
                    'simulation' => $selection->simulation,
                    'verification' => 'unverified',
                ],
                'derived_graph' => $graph,
                'generated_snapshot' => $generatedSnapshot,
                'regeneration_feedback' => null,
                'feedback_fingerprint' => $request->feedbackFingerprint,
                'generated_at' => now(),
            ]);

            $phases = [];
            foreach ($result->roadmap->phases as $position => $phase) {
                $phases[$phase->stableId] = RoadmapPhase::query()->create([
                    'roadmap_id' => $roadmap->id,
                    'stable_id' => $phase->stableId,
                    'name' => $phase->name,
                    'position' => $position + 1,
                ]);
            }

            $milestones = [];
            foreach ($result->roadmap->milestones as $position => $milestone) {
                $milestones[$milestone->stableId] = RoadmapMilestone::query()->create([
                    'roadmap_id' => $roadmap->id,
                    'roadmap_phase_id' => $phases[$milestone->phaseId]->id,
                    'stable_id' => $milestone->stableId,
                    'name' => $milestone->name,
                    'position' => $position + 1,
                ]);
            }

            $tasks = [];
            foreach ($result->roadmap->tasks as $position => $task) {
                $tasks[$task->stableId] = RoadmapTask::query()->create([
                    'roadmap_id' => $roadmap->id,
                    'roadmap_phase_id' => $phases[$task->phaseId]->id,
                    'roadmap_milestone_id' => $milestones[$task->milestoneId]->id,
                    'stable_id' => $task->stableId,
                    'title' => $task->title,
                    'objective' => $task->objective,
                    'ticket_type' => $task->ticketType,
                    'scope' => $task->scope,
                    'acceptance_criteria' => array_map(static fn ($criterion): array => $criterion->toArray(), $task->acceptanceCriteria),
                    'source_references' => array_map(static fn ($reference): array => $reference->toArray(), $task->sourceReferences),
                    'evidence_requirements' => $task->evidenceRequirements,
                    'priority' => $task->priority,
                    'risk' => $task->risk,
                    'reasoning_level' => $task->reasoningLevel->value,
                    'reasoning' => $task->reasoning,
                    'logical_agent' => $task->logicalAgent,
                    'estimated_complexity' => $task->estimatedComplexity,
                    'human_approval_required' => $task->humanApprovalRequired,
                    'position' => $position + 1,
                    'critical_path_rank' => $graph['critical_path_rank'][$task->stableId],
                    'critical_path_position' => $graph['critical_path_position'][$task->stableId],
                    'is_critical_path' => $graph['is_critical_path'][$task->stableId],
                ]);

                foreach ($task->acceptanceCriteria as $criterion) {
                    foreach ($criterion->sourceReferences as $reference) {
                        RoadmapTraceabilityLink::query()->create([
                            'roadmap_id' => $roadmap->id,
                            'roadmap_task_id' => $tasks[$task->stableId]->id,
                            'criterion_stable_id' => $criterion->stableId,
                            'project_context_snapshot_id' => $request->contextSnapshotId,
                            'document_id' => $reference->documentId,
                            'document_version_id' => $reference->documentVersionId,
                            'document_version' => $reference->version,
                            'checksum_sha256' => $reference->checksumSha256,
                        ]);
                    }
                }
            }

            foreach ($result->roadmap->dependencies as $dependency) {
                TaskDependency::query()->create([
                    'roadmap_id' => $roadmap->id,
                    'roadmap_task_id' => $tasks[$dependency->taskId]->id,
                    'depends_on_task_id' => $tasks[$dependency->dependsOnTaskId]->id,
                ]);
            }

            $this->events->generated($roadmap, $execution->correlation_id);

            return $roadmap;
        }, attempts: 3);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $persist();
            } catch (QueryException $exception) {
                $revisionConflict = $exception->getCode() === '23505'
                    && str_contains($exception->getMessage(), 'roadmaps_project_id_revision_unique');
                if (! $revisionConflict || $attempt === 3) {
                    throw $exception;
                }
            }
        }

        throw new LogicException('Roadmap revision persistence exhausted its retry boundary.');
    }
}
