<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Development\ProcessDevelopmentExecution;
use App\Application\QualityAssurance\ProcessQualityAssuranceExecution;
use App\Application\QualityAssurance\StartQualityAssuranceExecution;
use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\SelectNextTicketAndAcquireLease;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Tickets\TicketStatus;
use App\Models\Artifact;
use App\Models\Evidence;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\OrganizationMembership;
use App\Models\QaAssessment;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Builds deterministic completed Layer 2 and Layer 3 test lineage.
 */
final class CompletedQualityAssuranceFixture
{
    /**
     * @return array<string, mixed>
     */
    public static function create(
        string $scenario = 'merge_ready_low_risk',
    ): array {
        $fixture = TicketTestFixture::create(
            stableId: sprintf(
                'AIOS-PHASE-8-%s',
                Str::lower(Str::random(8)),
            ),
            ticketAttributes: [
                'title' => 'Exercise the simulated merge decision center',
                'objective' => 'Record an evidence-aware human disposition.',
                'status' => TicketStatus::Ready,
                'desired_state' => TicketStatus::Ready,
                'status_changed_at' => now()->subMinute(),
                'ready_at' => now()->subMinute(),
                'acceptance_criteria' => [
                    'Authorized users may decide the QA assessment.',
                ],
                'evidence_requirements' => [
                    'Automated decision workflow tests.',
                ],
                'logical_agent' => 'backend_engineer',
                'reasoning_level' => 'high',
            ],
            configurationSnapshot: [
                'policy' => [
                    'validation' => [
                        'commands' => ['php artisan test'],
                    ],
                ],
            ],
        );

        $fixture['roadmap']->forceFill([
            'status' => 'approved',
            'approved_fingerprint' => $fixture['roadmap']->candidate_fingerprint,
            'approved_snapshot' => ['schema_version' => 1],
            'approved_at' => now(),
        ])->save();

        $implementationExecution = Execution::factory()
            ->for($fixture['project'])
            ->create([
                'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
                'capability' => 'development.simulation',
                'logical_role' => 'backend_engineer',
                'retry_limit' => 2,
            ]);

        app(SelectNextTicketAndAcquireLease::class)->handle(
            new TicketSelectionRequest(
                organizationId: $fixture['project']->organization_id,
                projectId: $fixture['project']->id,
                executionId: $implementationExecution->id,
                owner: 'phase-eight-layer-2-worker',
            ),
        );

        app(ProcessDevelopmentExecution::class)->handle(
            execution: $implementationExecution,
            seed: 112,
        );

        $implementationAttempt = ExecutionAttempt::query()
            ->where('execution_id', $implementationExecution->id)
            ->latest('attempt_number')
            ->firstOrFail();

        $assessment = app(StartQualityAssuranceExecution::class)->handle(
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            roadmapTaskId: $fixture['ticket']->id,
            implementationExecutionId: $implementationExecution->id,
            implementationAttemptId: $implementationAttempt->id,
            scenario: $scenario,
            seed: 112,
        );

        app(ProcessQualityAssuranceExecution::class)->handle($assessment);

        return [
            ...$fixture,
            'implementationExecution' => $implementationExecution->refresh(),
            'implementationAttempt' => $implementationAttempt->refresh(),
            'assessment' => $assessment->refresh(),
        ];
    }

    /**
     * Create one user with an explicit organization role.
     */
    public static function user(
        int $organizationId,
        OrganizationRole $role = OrganizationRole::Owner,
    ): User {
        $user = User::factory()->create();

        OrganizationMembership::factory()->create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }

    /**
     * Build a simulated assessment whose only reference is expired evidence.
     *
     * @return array<string, mixed>
     */
    public static function createWithStaleEvidence(): array
    {
        $fixture = self::create();
        $providerExecution = Execution::factory()
            ->completed()
            ->for($fixture['project'])
            ->create([
                'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
                'capability' => 'evidence.verification',
            ]);
        $providerAttempt = ExecutionAttempt::factory()
            ->completed()
            ->for($providerExecution)
            ->create([
                'execution_provider' => 'github',
                'simulation_mode' => null,
                'simulation_seed' => null,
                'actual_state' => 'verified',
            ]);
        $artifact = Artifact::factory()
            ->forAttempt($providerAttempt)
            ->create();
        $evidence = Evidence::factory()
            ->verified()
            ->forArtifact($artifact)
            ->create([
                'observed_at' => now()->subHours(2),
                'verified_at' => now()->subHour(),
                'expires_at' => now()->subMinute(),
            ]);

        $fixture['assessment'] = self::addAssessmentWithEvidence(
            fixture: $fixture,
            evidenceIds: [$evidence->id],
        );
        $fixture['staleEvidence'] = $evidence;

        return $fixture;
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  list<string>  $evidenceIds
     */
    private static function addAssessmentWithEvidence(
        array $fixture,
        array $evidenceIds,
    ): QaAssessment {
        /** @var QaAssessment $source */
        $source = $fixture['assessment'];
        $implementationExecution = Execution::factory()
            ->completed()
            ->for($fixture['project'])
            ->create([
                'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
                'capability' => 'development.simulation',
                'logical_role' => 'backend_engineer',
            ]);
        $implementationAttempt = ExecutionAttempt::factory()
            ->completed()
            ->for($implementationExecution)
            ->create();
        $reviewExecution = Execution::factory()
            ->completed()
            ->for($fixture['project'])
            ->create([
                'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
                'capability' => 'quality_assurance.simulation',
                'logical_role' => 'quality_assurance_engineer',
            ]);
        $reviewAttempt = ExecutionAttempt::factory()
            ->completed()
            ->for($reviewExecution)
            ->create([
                'execution_provider' => 'simulation',
                'actual_state' => 'verified',
            ]);

        return QaAssessment::query()->create([
            'project_id' => $fixture['project']->id,
            'roadmap_task_id' => $fixture['ticket']->id,
            'implementation_execution_id' => $implementationExecution->id,
            'implementation_attempt_id' => $implementationAttempt->id,
            'review_execution_id' => $reviewExecution->id,
            'review_attempt_id' => $reviewAttempt->id,
            'status' => QaAssessment::STATUS_COMPLETED,
            'simulation_scenario' => 'stale_evidence',
            'simulation_seed' => 113,
            'result_schema_version' => $source->result_schema_version,
            'decision' => $source->decision,
            'confidence' => $source->confidence,
            'target_branch' => 'develop',
            'ticket_scope_satisfied' => true,
            'acceptance_criteria_verified' => true,
            'ci_status' => $source->ci_status,
            'test_status' => $source->test_status,
            'architecture_status' => $source->architecture_status,
            'security_status' => $source->security_status,
            'database_impact' => $source->database_impact,
            'performance_impact' => $source->performance_impact,
            'regression_risk' => $source->regression_risk,
            'rollback_complexity' => $source->rollback_complexity,
            'unresolved_findings' => [],
            'merge_risks' => [],
            'recommendation' => $source->recommendation,
            'evidence_ids' => $evidenceIds,
            'canonical_assessment_fingerprint' => hash(
                'sha256',
                implode(':', $evidenceIds),
            ),
            'created_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]);
    }
}
