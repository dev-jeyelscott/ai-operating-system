<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActorType;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Tickets\TicketActualState;
use App\Domain\Tickets\TicketStatus;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use App\Models\Roadmap;
use App\Models\RoadmapMilestone;
use App\Models\RoadmapPhase;
use App\Models\RoadmapTask;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Create one complete persisted roadmap task for AIOS-089 state-model tests.
 */
function aios089RoadmapTaskFixture(): RoadmapTask
{
    $project = Project::factory()->create();

    $configuration = ProjectConfigurationVersion::query()->create([
        'project_id' => $project->id,
        'schema_version' => 1,
        'revision' => 1,
        'actor_type' => AuditActorType::System,
        'actor_id' => 'aios-089-test-suite',
        'change_reason' => 'ticket_state_model_test',
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
            'aios-089-context',
        ),
        'approved_document_versions' => [],
    ]);

    $execution = Execution::factory()
        ->for($project)
        ->create([
            'project_context_snapshot_id' => $contextSnapshot->id,
            'capability' => 'planning.roadmap',
            'requested_reasoning_level' => ReasoningLevel::High,
        ]);

    $roadmap = Roadmap::query()->create([
        'project_id' => $project->id,
        'planning_execution_id' => $execution->id,
        'project_context_snapshot_id' => $contextSnapshot->id,
        'parent_roadmap_id' => null,
        'approval_id' => null,
        'schema_version' => 1,
        'revision' => 1,
        'content_version' => 1,
        'provider_id' => 'simulation',
        'scenario' => 'aios_089',
        'seed' => 89,
        'input_fingerprint' => hash(
            'sha256',
            'aios-089-input',
        ),
        'output_fingerprint' => hash(
            'sha256',
            'aios-089-output',
        ),
        'candidate_fingerprint' => hash(
            'sha256',
            'aios-089-candidate',
        ),
        'approved_fingerprint' => null,
        'status' => 'generated',
        'readiness' => 'ready',
        'goal' => 'Test internal ticket truth-state persistence.',
        'scope' => [],
        'assumptions' => [],
        'constraints' => [],
        'definition_of_done' => [],
        'required_approvals' => [],
        'document_inventory' => [],
        'document_summary' => 'AIOS-089 test roadmap.',
        'architecture_concerns' => [],
        'security_concerns' => [],
        'readiness_reasons' => [],
        'metadata' => null,
        'derived_graph' => null,
        'generated_snapshot' => [
            'schema_version' => 1,
        ],
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
        'stable_id' => 'm6',
        'name' => 'Layer 2 simulation',
        'position' => 1,
    ]);

    return RoadmapTask::query()->create([
        'roadmap_id' => $roadmap->id,
        'roadmap_phase_id' => $phase->id,
        'roadmap_milestone_id' => $milestone->id,
        'stable_id' => 'AIOS-089',
        'title' => 'Implement ticket state model',
        'objective' => 'Separate internal and evidence-backed state.',
        'ticket_type' => 'feature',
        'scope' => [
            'included' => [
                'Internal status',
                'Truth-state fields',
            ],
            'excluded' => [
                'Ticket leasing',
                'Layer 2 execution',
            ],
        ],
        'acceptance_criteria' => [],
        'source_references' => [],
        'evidence_requirements' => [],
        'priority' => 'medium',
        'risk' => 'high',
        'reasoning_level' => 'high',
        'reasoning' => 'State truth must not depend on provider claims.',
        'logical_agent' => 'backend_engineer',
        'estimated_complexity' => 5,
        'human_approval_required' => false,
        'position' => 1,
        'critical_path_rank' => null,
        'critical_path_position' => null,
        'is_critical_path' => false,
    ]);
}

test(
    'new roadmap tasks use safe internal state defaults',
    function (): void {
        $task = aios089RoadmapTaskFixture()->refresh();

        expect($task->status)
            ->toBe(TicketStatus::Backlog)
            ->and($task->desired_state)
            ->toBe(TicketStatus::Backlog)
            ->and($task->reported_state)
            ->toBeNull()
            ->and($task->observed_state)
            ->toBeNull()
            ->and($task->actual_state)
            ->toBe(TicketActualState::Unverified)
            ->and($task->status_changed_at)
            ->not->toBeNull()
            ->and($task->ready_at)
            ->toBeNull();
    },
);

test(
    'reported observed desired and actual state remain independent',
    function (): void {
        $task = aios089RoadmapTaskFixture();
        $readyAt = now();

        $task->forceFill([
            'status' => TicketStatus::Ready,
            'desired_state' => TicketStatus::InProgress,
            'reported_state' => 'tests_passed',
            'observed_state' => 'ci_run_missing',
            'actual_state' => TicketActualState::Unverified,
            'status_changed_at' => $readyAt,
            'ready_at' => $readyAt,
        ])->save();

        $task->refresh();

        expect($task->status)
            ->toBe(TicketStatus::Ready)
            ->and($task->desired_state)
            ->toBe(TicketStatus::InProgress)
            ->and($task->reported_state)
            ->toBe('tests_passed')
            ->and($task->observed_state)
            ->toBe('ci_run_missing')
            ->and($task->actual_state)
            ->toBe(TicketActualState::Unverified)
            ->and($task->actual_state->isVerified())
            ->toBeFalse()
            ->and($task->ready_at)
            ->not->toBeNull();
    },
);

test(
    'database rejects unsupported authoritative ticket values',
    function (string $column): void {
        $task = aios089RoadmapTaskFixture();

        expect(
            fn (): int => DB::table('roadmap_tasks')
                ->where('id', $task->id)
                ->update([
                    $column => 'unsupported_state',
                ]),
        )->toThrow(QueryException::class);
    },
)->with([
    'status',
    'desired_state',
    'actual_state',
]);

test(
    'database rejects unnormalized reported and observed states',
    function (string $column): void {
        $task = aios089RoadmapTaskFixture();

        expect(
            fn (): int => DB::table('roadmap_tasks')
                ->where('id', $task->id)
                ->update([
                    $column => 'Invalid External State',
                ]),
        )->toThrow(QueryException::class);
    },
)->with([
    'reported_state',
    'observed_state',
]);

test(
    'ticket status selection index is installed',
    function (): void {
        $indexes = DB::table('pg_indexes')
            ->where('tablename', 'roadmap_tasks')
            ->pluck('indexname')
            ->all();

        expect($indexes)->toContain(
            'roadmap_tasks_roadmap_status_ready_index',
        );
    },
);
