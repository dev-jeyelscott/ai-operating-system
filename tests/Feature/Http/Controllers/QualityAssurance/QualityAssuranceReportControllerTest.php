<?php

declare(strict_types=1);

use App\Application\Development\ProcessDevelopmentExecution;
use App\Application\QualityAssurance\GetProjectQualityAssuranceReport;
use App\Application\QualityAssurance\ProcessQualityAssuranceExecution;
use App\Application\QualityAssurance\StartQualityAssuranceExecution;
use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\SelectNextTicketAndAcquireLease;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Tickets\TicketStatus;
use App\Infrastructure\QualityAssurance\QaScenarioCatalog;
use App\Models\Artifact;
use App\Models\Evidence;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\QaAssessment;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TicketTestFixture;

/**
 * Create one authorized user inside the fixture organization.
 */
function aios111ProjectViewer(
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
 * Build one completed Layer 2 and high-risk Layer 3 simulation.
 *
 * @return array<string, mixed>
 */
function aios111CompletedAssessmentFixture(): array
{
    $fixture = TicketTestFixture::create(
        stableId: sprintf('AIOS-111-%s', Str::lower(Str::random(8))),
        ticketAttributes: [
            'title' => 'Build QA report and risk matrix UI',
            'objective' => 'Present the latest independent QA assessment.',
            'status' => TicketStatus::Ready,
            'desired_state' => TicketStatus::Ready,
            'status_changed_at' => now()->subMinute(),
            'ready_at' => now()->subMinute(),
            'acceptance_criteria' => [
                'Findings show severity, impact, mitigation, and evidence.',
            ],
            'evidence_requirements' => [
                'Frontend and endpoint tests.',
            ],
            'logical_agent' => 'frontend_engineer',
            'reasoning_level' => 'medium',
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
            'logical_role' => 'frontend_engineer',
            'retry_limit' => 2,
        ]);

    app(SelectNextTicketAndAcquireLease::class)->handle(
        new TicketSelectionRequest(
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            executionId: $implementationExecution->id,
            owner: 'aios-111-layer-2-worker',
        ),
    );

    app(ProcessDevelopmentExecution::class)->handle(
        execution: $implementationExecution,
        seed: 111,
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
        scenario: QaScenarioCatalog::MERGE_READY_HIGH_RISK,
        seed: 111,
    );

    app(ProcessQualityAssuranceExecution::class)->handle($assessment);

    return [
        ...$fixture,
        'assessment' => $assessment->refresh(),
    ];
}

/**
 * Add a newer completed assessment with explicit evidence references.
 *
 * @param  array<string, mixed>  $fixture
 * @param  list<string>  $evidenceIds
 */
function aios113AssessmentWithEvidence(
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
        'simulation_scenario' => 'evidence_safeguard',
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

test(
    'an authorized project member can load the latest QA report',
    function (): void {
        $fixture = aios111CompletedAssessmentFixture();
        $project = $fixture['project'];
        $assessment = $fixture['assessment'];
        $organization = Organization::query()->findOrFail(
            $project->organization_id,
        );
        $viewer = aios111ProjectViewer($organization->id);
        $evidenceId = (string) data_get(
            $assessment->unresolved_findings,
            '0.evidence_ids.0',
        );

        $response = $this->actingAs($viewer)->get(route(
            'organizations.projects.quality-assurance.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ));

        $response->assertOk()->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('projects/quality-assurance/show')
                ->where('organization.id', $organization->id)
                ->where('project.id', $project->id)
                ->missing('report')
                ->loadDeferredProps(
                    fn (Assert $reload): Assert => $reload
                        ->where('report.assessment.id', $assessment->id)
                        ->where(
                            'report.assessment.decision',
                            'merge_ready_with_risks',
                        )
                        ->where('report.assessment.targetBranch', 'develop')
                        ->where(
                            'report.assessment.findings.0.code',
                            'QA-RISK-001',
                        )
                        ->where(
                            'report.assessment.findings.0.severity',
                            'high',
                        )
                        ->where(
                            'report.assessment.findings.0.evidenceIds.0',
                            $evidenceId,
                        )
                        ->where(
                            'report.assessment.mergeRisks.0.code',
                            'MERGE-HIGH-001',
                        )
                        ->where(
                            'report.assessment.evidenceReferences.0.id',
                            $evidenceId,
                        )
                        ->where(
                            'report.assessment.provenance.isSimulated',
                            true,
                        )
                        ->where(
                            'report.assessment.provenance.actualState',
                            'unverified',
                        )
                        ->where(
                            'report.assessment.evidenceReferences.0.state',
                            'simulated',
                        )
                        ->where(
                            'report.assessment.evidenceReferences.0.verified',
                            false,
                        )
                        ->where(
                            'report.assessment.evidenceSummary.allCurrentlyVerified',
                            false,
                        )
                        ->where(
                            'report.assessment.provenance.evidenceStillRequired',
                            true,
                        )
                        ->where(
                            'report.assessment.decisionCenter.canSubmit',
                            true,
                        )
                        ->where(
                            'report.assessment.decisionCenter.allowedActions',
                            [
                                'approve',
                                'request_changes',
                                'escalate',
                                'defer',
                            ],
                        ),
                ),
        );
    },
);

test(
    'stale missing and cross-project evidence are never presented as verified',
    function (): void {
        $fixture = aios111CompletedAssessmentFixture();
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
        $staleEvidence = Evidence::factory()
            ->verified()
            ->forArtifact($artifact)
            ->create([
                'observed_at' => now()->subHours(2),
                'verified_at' => now()->subHour(),
                'expires_at' => now()->subSecond(),
            ]);
        $crossProjectEvidence = Evidence::factory()->verified()->create();
        $missingId = (string) Str::ulid();
        $assessment = aios113AssessmentWithEvidence(
            $fixture,
            [
                $staleEvidence->id,
                $crossProjectEvidence->id,
                $missingId,
            ],
        );

        $report = app(GetProjectQualityAssuranceReport::class)->handle(
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
        );
        $references = collect($report['assessment']['evidenceReferences'])
            ->keyBy('id');

        expect($report['assessment']['id'])->toBe($assessment->id)
            ->and($references[$staleEvidence->id]['state'])->toBe('stale')
            ->and($references[$staleEvidence->id]['verified'])->toBeFalse()
            ->and($references[$staleEvidence->id]['reasonCode'])
            ->toBe('evidence.expired')
            ->and($references[$crossProjectEvidence->id]['state'])
            ->toBe('missing')
            ->and($references[$crossProjectEvidence->id]['sourceReference'])
            ->toBeNull()
            ->and($references[$missingId]['state'])->toBe('missing')
            ->and($report['assessment']['evidenceSummary']['stale'])->toBe(1)
            ->and($report['assessment']['evidenceSummary']['missing'])->toBe(2)
            ->and($report['assessment']['provenance']['actualState'])
            ->toBe('unverified')
            ->and($report['assessment']['provenance']['evidenceStillRequired'])
            ->toBeTrue();
    },
);

test(
    'the QA report returns a neutral empty state before Layer 3 runs',
    function (): void {
        $fixture = TicketTestFixture::create(
            stableId: sprintf(
                'AIOS-111-EMPTY-%s',
                Str::lower(Str::random(8)),
            ),
        );
        $project = $fixture['project'];
        $organization = Organization::query()->findOrFail(
            $project->organization_id,
        );
        $viewer = aios111ProjectViewer($organization->id);

        $this->actingAs($viewer)
            ->get(route(
                'organizations.projects.quality-assurance.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->missing('report')
                    ->loadDeferredProps(
                        fn (Assert $reload): Assert => $reload
                            ->where('report.assessment', null),
                    ),
            );
    },
);

test(
    'a user outside the organization cannot discover the QA report',
    function (): void {
        $fixture = TicketTestFixture::create(
            stableId: sprintf(
                'AIOS-111-FORBIDDEN-%s',
                Str::lower(Str::random(8)),
            ),
        );
        $project = $fixture['project'];
        $organization = Organization::query()->findOrFail(
            $project->organization_id,
        );
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route(
                'organizations.projects.quality-assurance.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ))
            ->assertNotFound();
    },
);
