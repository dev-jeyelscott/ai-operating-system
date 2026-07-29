<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Audit\AuditActorType;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use App\Models\Roadmap;
use App\Models\RoadmapMilestone;
use App\Models\RoadmapPhase;
use App\Models\RoadmapTask;

/**
 * Creates a complete project, roadmap, and ticket lineage for feature tests.
 */
final class TicketTestFixture
{
    /**
     * @param  array<string, mixed>  $ticketAttributes
     * @return array{project: Project, roadmap: Roadmap, ticket: RoadmapTask}
     */
    public static function create(
        string $stableId = 'AIOS-089',
        array $ticketAttributes = [],
    ): array {
        $project = Project::factory()->create();
        $configuration = ProjectConfigurationVersion::query()->create([
            'project_id' => $project->id,
            'schema_version' => 1,
            'revision' => 1,
            'actor_type' => AuditActorType::System,
            'actor_id' => 'ticket-test-fixture',
            'change_reason' => 'ticket_feature_test',
            'snapshot' => [
                'schema_version' => 1,
                'revision' => 1,
            ],
        ]);
        $contextSnapshot = ProjectContextSnapshot::query()->create([
            'project_id' => $project->id,
            'project_configuration_version_id' => $configuration->id,
            'configuration_revision' => 1,
            'identity_schema_version' => 1,
            'approved_document_set_fingerprint' => hash(
                'sha256',
                'ticket-test-context-'.$stableId,
            ),
            'approved_document_versions' => [],
        ]);
        $planningExecution = Execution::factory()
            ->for($project)
            ->create([
                'project_context_snapshot_id' => $contextSnapshot->id,
                'capability' => 'planning.roadmap',
                'requested_reasoning_level' => ReasoningLevel::High,
            ]);
        $roadmap = Roadmap::query()->create([
            'project_id' => $project->id,
            'planning_execution_id' => $planningExecution->id,
            'project_context_snapshot_id' => $contextSnapshot->id,
            'parent_roadmap_id' => null,
            'approval_id' => null,
            'schema_version' => 1,
            'revision' => 1,
            'content_version' => 1,
            'provider_id' => 'simulation',
            'scenario' => 'ticket_feature_test',
            'seed' => 89,
            'input_fingerprint' => hash('sha256', 'input-'.$stableId),
            'output_fingerprint' => hash('sha256', 'output-'.$stableId),
            'candidate_fingerprint' => hash('sha256', 'candidate-'.$stableId),
            'approved_fingerprint' => null,
            'status' => 'generated',
            'readiness' => 'ready',
            'goal' => 'Exercise authoritative ticket behavior.',
            'scope' => [],
            'assumptions' => [],
            'constraints' => [],
            'definition_of_done' => [],
            'required_approvals' => [],
            'document_inventory' => [],
            'document_summary' => 'Ticket feature-test roadmap.',
            'architecture_concerns' => [],
            'security_concerns' => [],
            'readiness_reasons' => [],
            'metadata' => null,
            'derived_graph' => null,
            'generated_snapshot' => ['schema_version' => 1],
            'approved_snapshot' => null,
            'regeneration_feedback' => null,
            'feedback_fingerprint' => null,
            'generated_at' => now(),
            'approved_at' => null,
        ]);
        $phase = RoadmapPhase::query()->create([
            'roadmap_id' => $roadmap->id,
            'stable_id' => 'phase-7',
            'name' => 'Ticket execution',
            'position' => 1,
        ]);
        $milestone = RoadmapMilestone::query()->create([
            'roadmap_id' => $roadmap->id,
            'roadmap_phase_id' => $phase->id,
            'stable_id' => 'layer-2',
            'name' => 'Layer 2 simulation',
            'position' => 1,
        ]);
        $ticket = RoadmapTask::query()->create(array_merge([
            'roadmap_id' => $roadmap->id,
            'roadmap_phase_id' => $phase->id,
            'roadmap_milestone_id' => $milestone->id,
            'stable_id' => $stableId,
            'title' => 'Implement ticket state behavior',
            'objective' => 'Preserve authoritative ticket truth.',
            'ticket_type' => 'feature',
            'scope' => ['included' => [], 'excluded' => []],
            'acceptance_criteria' => [],
            'source_references' => [],
            'evidence_requirements' => [],
            'priority' => 'medium',
            'risk' => 'high',
            'reasoning_level' => 'high',
            'reasoning' => 'Ticket state requires deterministic transitions.',
            'logical_agent' => 'backend_engineer',
            'estimated_complexity' => 5,
            'human_approval_required' => false,
            'position' => 1,
            'critical_path_rank' => null,
            'critical_path_position' => null,
            'is_critical_path' => false,
        ], $ticketAttributes));

        return [
            'project' => $project,
            'roadmap' => $roadmap,
            'ticket' => $ticket,
        ];
    }
}
