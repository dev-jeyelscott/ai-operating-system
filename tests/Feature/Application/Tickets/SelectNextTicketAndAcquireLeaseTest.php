<?php

declare(strict_types=1);

namespace Tests\Feature\Application\Tickets;

use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\SelectNextTicketAndAcquireLease;
use App\Domain\Audit\AuditActorType;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Tickets\TicketActualState;
use App\Domain\Tickets\TicketStatus;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use App\Models\Roadmap;
use App\Models\RoadmapMilestone;
use App\Models\RoadmapPhase;
use App\Models\RoadmapTask;
use App\Models\TicketExecutionLease;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

final class SelectNextTicketAndAcquireLeaseTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Verify the approved ranking policy determines the claimed ticket.
     */
    public function test_highest_ranked_eligible_ticket_is_selected_and_leased_atomically(): void
    {
        $fixture = $this->selectionFixture();

        $this->readyTicket(
            roadmap: $fixture['roadmap'],
            stableId: 'AIOS-LOWER-RANK',
            position: 2,
            overrides: [
                'priority' => 'critical',
                'is_critical_path' => true,
                'critical_path_rank' => 99,
            ],
        );

        $expected = $this->readyTicket(
            roadmap: $fixture['roadmap'],
            stableId: 'AIOS-HIGHEST-RANK',
            position: 1,
            overrides: [
                'priority' => 'low',
            ],
        );

        $result = $this->selector()->handle(
            $this->selectionRequest(
                project: $fixture['project'],
                execution: $fixture['developmentExecution'],
            ),
        );

        $this->assertTrue($result->isSelected());
        $this->assertSame($expected->id, $result->roadmapTaskId);
        $this->assertSame($expected->stable_id, $result->ticketId);

        $this->assertDatabaseHas('ticket_execution_leases', [
            'id' => $result->leaseId,
            'project_id' => $fixture['project']->id,
            'roadmap_task_id' => $expected->id,
            'execution_id' => $fixture['developmentExecution']->id,
            'released_at' => null,
        ]);

        $this->assertDatabaseHas('outbox_messages', [
            'event_name' => 'ticket.selected',
            'aggregate_type' => 'roadmap_task',
            'aggregate_id' => (string) $expected->id,
            'execution_id' => $fixture['developmentExecution']->id,
        ]);

        $this->assertDatabaseHas('outbox_messages', [
            'event_name' => 'ticket.lease_acquired',
            'aggregate_type' => 'ticket_execution_lease',
            'aggregate_id' => $result->leaseId,
            'execution_id' => $fixture['developmentExecution']->id,
        ]);
    }

    /**
     * Verify replaying the same execution cannot create a second lease.
     */
    public function test_same_execution_replay_returns_the_original_lease(): void
    {
        $fixture = $this->selectionFixture();

        $this->readyTicket(
            roadmap: $fixture['roadmap'],
            stableId: 'AIOS-IDEMPOTENT',
            position: 1,
        );

        $request = $this->selectionRequest(
            project: $fixture['project'],
            execution: $fixture['developmentExecution'],
        );

        $first = $this->selector()->handle($request);
        $second = $this->selector()->handle($request);

        $this->assertNotNull($first->leaseId);
        $this->assertSame($first->leaseId, $second->leaseId);
        $this->assertSame(
            $first->roadmapTaskId,
            $second->roadmapTaskId,
        );

        $this->assertDatabaseCount('ticket_execution_leases', 1);
        $this->assertDatabaseCount('outbox_messages', 2);
    }

    /**
     * Verify a row held by another selector is skipped without waiting or
     * creating a competing lease.
     */
    public function test_locked_ticket_is_skipped_by_a_concurrent_selector(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'AIOS-092 concurrency verification requires PostgreSQL.',
            );
        }

        $fixture = $this->selectionFixture();

        $ticket = $this->readyTicket(
            roadmap: $fixture['roadmap'],
            stableId: 'AIOS-CONTENDED',
            position: 1,
        );

        $pdo = $this->independentPdo();
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                'SELECT id FROM roadmap_tasks WHERE id = ? FOR UPDATE',
            );

            $statement->execute([$ticket->id]);

            $result = $this->selector()->handle(
                $this->selectionRequest(
                    project: $fixture['project'],
                    execution: $fixture['developmentExecution'],
                ),
            );

            $this->assertFalse($result->isSelected());
            $this->assertDatabaseCount('ticket_execution_leases', 0);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $resultAfterRelease = $this->selector()->handle(
            $this->selectionRequest(
                project: $fixture['project'],
                execution: $fixture['developmentExecution'],
            ),
        );

        $this->assertSame(
            $ticket->id,
            $resultAfterRelease->roadmapTaskId,
        );
    }

    /**
     * Verify PostgreSQL remains the final defense against duplicate claims.
     */
    public function test_database_rejects_two_active_leases_for_the_same_ticket(): void
    {
        $fixture = $this->selectionFixture();

        $ticket = $this->readyTicket(
            roadmap: $fixture['roadmap'],
            stableId: 'AIOS-UNIQUE-ACTIVE-LEASE',
            position: 1,
        );

        $firstExecution = $this->developmentExecution($fixture);
        $secondExecution = $this->developmentExecution($fixture);
        $acquiredAt = CarbonImmutable::now();

        TicketExecutionLease::query()->create([
            'project_id' => $fixture['project']->id,
            'roadmap_task_id' => $ticket->id,
            'execution_id' => $firstExecution->id,
            'owner' => 'first-worker',
            'acquired_at' => $acquiredAt,
            'expires_at' => $acquiredAt->addMinutes(5),
            'heartbeat_at' => $acquiredAt,
        ]);

        $this->expectException(QueryException::class);

        TicketExecutionLease::query()->create([
            'project_id' => $fixture['project']->id,
            'roadmap_task_id' => $ticket->id,
            'execution_id' => $secondExecution->id,
            'owner' => 'second-worker',
            'acquired_at' => $acquiredAt,
            'expires_at' => $acquiredAt->addMinutes(5),
            'heartbeat_at' => $acquiredAt,
        ]);
    }

    /**
     * Resolve the selector from Laravel's zero-configuration container.
     */
    private function selector(): SelectNextTicketAndAcquireLease
    {
        return $this->app->make(
            SelectNextTicketAndAcquireLease::class,
        );
    }

    /**
     * Create the committed PostgreSQL fixture required by AIOS-092 tests.
     *
     * The roadmap snapshot fields intentionally use associative arrays because
     * PostgreSQL requires generated and approved snapshots to be JSON objects.
     *
     * @return array{
     *     project:Project,
     *     roadmap:Roadmap,
     *     developmentExecution:Execution
     * }
     */
    private function selectionFixture(): array
    {
        $project = Project::factory()
            ->status(ProjectStatus::Active)
            ->create();

        $configuration = ProjectConfiguration::factory()
            ->complete()
            ->create([
                'project_id' => $project->id,
            ]);

        $configurationVersion =
            ProjectConfigurationVersion::query()->create([
                'project_id' => $project->id,
                'schema_version' => $configuration->schema_version,
                'revision' => $configuration->revision,
                'actor_type' => AuditActorType::System,
                'actor_id' => 'aios-092-test',
                'change_reason' => 'Create atomic selection test fixture.',
                'snapshot' => $configuration->toVersionedArray(),
                'created_at' => now(),
            ]);

        $contextSnapshot = ProjectContextSnapshot::query()->create([
            'project_id' => $project->id,
            'project_configuration_version_id' => $configurationVersion->id,
            'configuration_revision' => $configuration->revision,
            'identity_schema_version' => 1,
            'approved_document_set_fingerprint' => hash(
                'sha256',
                'aios-092-documents',
            ),
            'approved_document_versions' => [],
        ]);

        $planningExecution = Execution::factory()->create([
            'project_id' => $project->id,
            'project_context_snapshot_id' => $contextSnapshot->id,
            'capability' => 'planning',
            'logical_role' => 'project_manager',
        ]);

        $developmentExecution = Execution::factory()->create([
            'project_id' => $project->id,
            'project_context_snapshot_id' => $contextSnapshot->id,
            'capability' => 'development',
            'logical_role' => 'backend_engineer',
        ]);

        /*
     * A non-list associative PHP array is persisted as a JSON object.
     *
     * This is deliberately minimal because this test verifies ticket
     * selection, not planning-result serialization.
     */
        $generatedSnapshot = [
            'schema_version' => 1,
            'fixture' => 'aios-092',
            'readiness' => 'ready',
            'roadmap' => [
                'phases' => [
                    [
                        'stable_id' => 'phase-execution',
                        'name' => 'Execution',
                    ],
                ],
                'milestones' => [
                    [
                        'stable_id' => 'milestone-ticket-selection',
                        'phase_id' => 'phase-execution',
                        'name' => 'Ticket selection',
                    ],
                ],
                'tasks' => [],
                'dependencies' => [],
            ],
        ];

        $outputFingerprint = hash(
            'sha256',
            json_encode(
                $generatedSnapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );

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
            'scenario' => 'happy_path',
            'seed' => 92,
            'input_fingerprint' => hash(
                'sha256',
                'aios-092-input',
            ),
            'output_fingerprint' => $outputFingerprint,
            'candidate_fingerprint' => $outputFingerprint,
            'approved_fingerprint' => $outputFingerprint,
            'status' => 'approved',
            'readiness' => 'ready',
            'goal' => 'Verify atomic ticket selection.',
            'scope' => [],
            'assumptions' => [],
            'constraints' => [],
            'definition_of_done' => [],
            'required_approvals' => [],
            'document_inventory' => [],
            'document_summary' => 'Deterministic test fixture.',
            'architecture_concerns' => [],
            'security_concerns' => [],
            'readiness_reasons' => [],
            'metadata' => [
                'simulation' => true,
                'verification' => 'unverified',
            ],
            'derived_graph' => [
                'critical_path' => [],
                'critical_path_rank' => [],
                'critical_path_position' => [],
                'is_critical_path' => [],
            ],
            'generated_snapshot' => $generatedSnapshot,
            'approved_snapshot' => $generatedSnapshot,
            'regeneration_feedback' => null,
            'feedback_fingerprint' => null,
            'generated_at' => now()->subMinute(),
            'approved_at' => now(),
        ]);

        /*
     * Roadmap tasks require valid phase and milestone ownership.
     * These records are shared by every ticket created in this fixture.
     */
        $phase = RoadmapPhase::query()->create([
            'roadmap_id' => $roadmap->id,
            'stable_id' => 'phase-execution',
            'name' => 'Execution',
            'position' => 1,
        ]);

        RoadmapMilestone::query()->create([
            'roadmap_id' => $roadmap->id,
            'roadmap_phase_id' => $phase->id,
            'stable_id' => 'milestone-ticket-selection',
            'name' => 'Ticket selection',
            'position' => 1,
        ]);

        return [
            'project' => $project,
            'roadmap' => $roadmap,
            'developmentExecution' => $developmentExecution,
        ];
    }

    /**
     * Create one valid ready ticket with optional ranking overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function readyTicket(
        Roadmap $roadmap,
        string $stableId,
        int $position,
        array $overrides = [],
    ): RoadmapTask {
        $phase = RoadmapPhase::query()
            ->where('roadmap_id', $roadmap->id)
            ->where('stable_id', 'phase-execution')
            ->firstOrFail();

        $milestone = RoadmapMilestone::query()
            ->where('roadmap_id', $roadmap->id)
            ->where('roadmap_phase_id', $phase->id)
            ->where(
                'stable_id',
                'milestone-ticket-selection',
            )
            ->firstOrFail();

        return RoadmapTask::query()->create(array_merge([
            'roadmap_id' => $roadmap->id,
            'roadmap_phase_id' => $phase->id,
            'roadmap_milestone_id' => $milestone->id,
            'stable_id' => $stableId,
            'title' => $stableId,
            'objective' => 'Implement '.$stableId.'.',
            'ticket_type' => 'feature',
            'scope' => [
                'included' => [],
                'excluded' => [],
            ],
            'acceptance_criteria' => [],
            'source_references' => [],
            'evidence_requirements' => [],
            'notion_body_overrides' => null,
            'priority' => 'medium',
            'risk' => 'medium',
            'reasoning_level' => 'high',
            'reasoning' => 'Concurrency-sensitive persistence work.',
            'logical_agent' => 'database_engineer',
            'estimated_complexity' => 5,
            'human_approval_required' => false,
            'position' => $position,
            'critical_path_rank' => 0,
            'critical_path_position' => null,
            'is_critical_path' => false,
            'status' => TicketStatus::Ready,
            'desired_state' => TicketStatus::Ready,
            'reported_state' => null,
            'observed_state' => null,
            'actual_state' => TicketActualState::Unverified,
            'status_changed_at' => now()->subMinutes(10),
            'ready_at' => now()->subMinutes(10),
        ], $overrides));
    }

    /**
     * Build the deterministic selection request for a fixture execution.
     */
    private function selectionRequest(
        Project $project,
        Execution $execution,
    ): TicketSelectionRequest {
        return new TicketSelectionRequest(
            organizationId: $project->organization_id,
            projectId: $project->id,
            executionId: $execution->id,
            owner: 'layer-2-worker-01',
            providerSupportsExecution: true,
            budgetPermitsExecution: true,
            leaseDurationSeconds: 300,
        );
    }

    /**
     * Create another development execution in the same immutable context.
     *
     * @param  array{
     *     project:Project,
     *     roadmap:Roadmap,
     *     developmentExecution:Execution
     * }  $fixture
     */
    private function developmentExecution(array $fixture): Execution
    {
        return Execution::factory()->create([
            'project_id' => $fixture['project']->id,
            'project_context_snapshot_id' => $fixture['developmentExecution']
                ->project_context_snapshot_id,
            'capability' => 'development',
        ]);
    }

    /**
     * Open a second independent PostgreSQL connection for contention tests.
     */
    private function independentPdo(): PDO
    {
        $connectionName = (string) config('database.default');

        /** @var array<string, mixed> $configuration */
        $configuration = config(
            'database.connections.'.$connectionName,
        );

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            (string) $configuration['host'],
            (string) $configuration['port'],
            (string) $configuration['database'],
        );

        return new PDO(
            $dsn,
            (string) $configuration['username'],
            (string) $configuration['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }
}
