<?php

declare(strict_types=1);

use App\Application\Operations\GetProjectOperationsReadModel;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\QualityAssurance\MergeDecisionAction;
use App\Domain\Tickets\TicketStatus;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\MergeDecision;
use App\Models\Roadmap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CompletedQualityAssuranceFixture;
use Tests\Support\TicketTestFixture;

uses(RefreshDatabase::class);

/**
 * Mark a generated fixture roadmap as the approved project roadmap.
 */
function approveOperationsFixtureRoadmap(
    Roadmap $roadmap,
): void {
    $roadmap->forceFill([
        'status' => 'approved',
        'approved_fingerprint' => $roadmap->candidate_fingerprint,
        'approved_snapshot' => [
            'schema_version' => 1,
        ],
        'approved_at' => now(),
    ])->save();
}

it('summarizes tickets blockers approvals retries layers and agents', function (): void {
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-117-BLOCKED',
        ticketAttributes: [
            'status' => TicketStatus::Blocked,
            'desired_state' => TicketStatus::Blocked,
            'reported_state' => 'blocked',
            'observed_state' => 'blocked',
            'status_changed_at' => now(),
        ],
    );

    approveOperationsFixtureRoadmap($fixture['roadmap']);

    $project = $fixture['project'];

    Execution::factory()
        ->for($project)
        ->create([
            'capability' => 'development.simulation',
            'logical_role' => 'backend_engineer',
            'status' => ExecutionStatus::RetryScheduled,
            'attempt_count' => 1,
            'retry_limit' => 3,
            'next_attempt_at' => now()->addMinute(),
        ]);

    Approval::factory()
        ->for($project)
        ->create([
            'request_payload' => [
                'roadmap_id' => $fixture['roadmap']->id,
            ],
        ]);

    $first = app(GetProjectOperationsReadModel::class)
        ->handle(
            organizationId: $project->organization_id,
            projectId: $project->id,
        );

    $second = app(GetProjectOperationsReadModel::class)
        ->handle(
            organizationId: $project->organization_id,
            projectId: $project->id,
        );

    expect($first)
        ->toHaveKey('project')
        ->toHaveKey('workflow')
        ->toHaveKey('summary')
        ->toHaveKey('layers')
        ->toHaveKey('agents')
        ->toHaveKey('tickets')
        ->toHaveKey('blockers')
        ->toHaveKey('approvals')
        ->toHaveKey('retries')
        ->toHaveKey('decisions');

    expect($first['project']['id'])->toBe($project->id)
        ->and($first['summary']['ticketsTotal'])->toBe(1)
        ->and(
            $first['summary']['ticketsByStatus']['blocked'],
        )->toBe(1)
        ->and($first['summary']['blockers'])->toBe(1)
        ->and($first['summary']['pendingApprovals'])->toBe(1)
        ->and($first['summary']['retriesScheduled'])->toBe(1)
        ->and($first['agents'][0]['layer'])->toBe('development')
        ->and($first['agents'][0]['state'])->toBe('retry_scheduled')
        ->and($first['metadata']['fingerprint'])
        ->toBe($second['metadata']['fingerprint']);
});

it('includes recent evidence-aware merge decisions', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();

    $project = $fixture['project'];
    $ticket = $fixture['ticket'];
    $assessment = $fixture['assessment'];

    $actor = CompletedQualityAssuranceFixture::user(
        $project->organization_id,
    );

    $assessmentFingerprint = (string) $assessment
        ->canonical_assessment_fingerprint;

    MergeDecision::query()->create([
        'project_id' => $project->id,
        'roadmap_task_id' => $ticket->id,
        'qa_assessment_id' => $assessment->id,
        'actor_user_id' => $actor->id,
        'action' => MergeDecisionAction::Defer,
        'reason' => 'Wait for the scheduled review window.',
        'idempotency_key_hash' => hash(
            'sha256',
            (string) Str::ulid(),
        ),
        'request_fingerprint' => hash(
            'sha256',
            'operations-read-model-decision',
        ),
        'correlation_id' => (string) Str::ulid(),
        'causation_id' => null,
        'assessment_decision' => $assessment->decision->value,
        'assessment_fingerprint' => $assessmentFingerprint,
        'ticket_status_before' => $ticket->status->value,
        'ticket_status_after' => $ticket->status->value,
        'terminal_marker' => null,
        'simulated' => true,
        'actual_state' => 'unverified',
        'decided_at' => now(),
    ]);

    $result = app(GetProjectOperationsReadModel::class)
        ->handle(
            organizationId: $project->organization_id,
            projectId: $project->id,
        );

    expect($result['summary']['recentDecisions'])->toBe(1)
        ->and($result['decisions'][0]['action'])->toBe('defer')
        ->and($result['decisions'][0]['ticketId'])
        ->toBe($ticket->stable_id)
        ->and($result['decisions'][0]['simulated'])->toBeTrue()
        ->and($result['decisions'][0]['actualState'])
        ->toBe('unverified');
});

it('returns the project operations contract to authorized members', function (): void {
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-117-ENDPOINT',
    );

    approveOperationsFixtureRoadmap($fixture['roadmap']);

    $project = $fixture['project'];

    $user = CompletedQualityAssuranceFixture::user(
        $project->organization_id,
    );

    $this
        ->actingAs($user)
        ->getJson(route(
            'organizations.projects.operations.show',
            [
                'organization' => $project->organization,
                'project' => $project,
            ],
        ))
        ->assertOk()
        ->assertJsonPath('metadata.schemaVersion', 1)
        ->assertJsonPath('project.id', $project->id)
        ->assertJsonPath('summary.ticketsTotal', 1)
        ->assertJsonStructure([
            'metadata' => [
                'schemaVersion',
                'asOf',
                'fingerprint',
            ],
            'project',
            'workflow',
            'roadmap',
            'summary',
            'layers',
            'agents',
            'tickets',
            'blockers',
            'approvals',
            'retries',
            'decisions',
        ]);
});

it('conceals a project operations endpoint from another organization', function (): void {
    $currentFixture = TicketTestFixture::create(
        stableId: 'AIOS-117-CURRENT',
    );

    $otherFixture = TicketTestFixture::create(
        stableId: 'AIOS-117-OTHER',
    );

    $user = CompletedQualityAssuranceFixture::user(
        $currentFixture['project']->organization_id,
    );

    $this
        ->actingAs($user)
        ->getJson(route(
            'organizations.projects.operations.show',
            [
                'organization' => $otherFixture['project']->organization,
                'project' => $otherFixture['project'],
            ],
        ))
        ->assertNotFound();
});
