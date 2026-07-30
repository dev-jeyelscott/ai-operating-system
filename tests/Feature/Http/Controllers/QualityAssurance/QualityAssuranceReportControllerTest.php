<?php

declare(strict_types=1);

use App\Application\Development\ProcessDevelopmentExecution;
use App\Application\QualityAssurance\ProcessQualityAssuranceExecution;
use App\Application\QualityAssurance\StartQualityAssuranceExecution;
use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\SelectNextTicketAndAcquireLease;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Tickets\TicketStatus;
use App\Infrastructure\QualityAssurance\QaScenarioCatalog;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Organization;
use App\Models\OrganizationMembership;
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
                        ),
                ),
        );
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
