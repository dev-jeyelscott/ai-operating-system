<?php

declare(strict_types=1);

use App\Application\Development\GetDevelopmentExecutionInspector;
use App\Application\Development\ProcessDevelopmentExecution;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Tickets\TicketStatus;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\TicketExecutionLease;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TicketTestFixture;

/** @return array<string, mixed> */
function aios101InspectorFixture(bool $process = true): array
{
    $fixture = TicketTestFixture::create(ticketAttributes: [
        'stable_id' => 'AIOS-101', 'title' => 'Build execution inspector',
        'status' => TicketStatus::Ready, 'desired_state' => TicketStatus::Ready,
        'status_changed_at' => now()->subMinute(), 'ready_at' => now()->subMinute(),
    ], configurationSnapshot: [
        'policy' => ['validation' => ['commands' => ['php artisan test', 'composer types:check']]],
    ]);
    $fixture['roadmap']->forceFill([
        'status' => 'approved', 'approved_at' => now(),
        'approved_fingerprint' => hash('sha256', 'aios-101-approved'),
        'approved_snapshot' => ['schema_version' => 1],
    ])->save();
    $execution = Execution::factory()->for($fixture['project'])->create([
        'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
        'capability' => 'development.simulation',
        'requested_reasoning_level' => ReasoningLevel::High,
    ]);
    $now = CarbonImmutable::now();
    $lease = TicketExecutionLease::query()->create([
        'project_id' => $fixture['project']->id,
        'roadmap_task_id' => $fixture['ticket']->id,
        'execution_id' => $execution->id,
        'owner' => 'development-inspector-test',
        'acquired_at' => $now,
        'heartbeat_at' => $now,
        'expires_at' => $now->addMinutes(5),
    ]);

    if ($process) {
        app(ProcessDevelopmentExecution::class)->handle($execution, seed: 101);
    }

    return [...$fixture, 'execution' => $execution->refresh(), 'lease' => $lease->refresh()];
}

function aios101Member(array $fixture): User
{
    $user = User::factory()->create();
    OrganizationMembership::factory()
        ->for($fixture['project']->organization)
        ->for($user)
        ->create();

    return $user;
}

test('DevelopmentExecutionInspector returns ordered, whitelisted simulated provenance', function (): void {
    $fixture = aios101InspectorFixture();
    $inspector = app(GetDevelopmentExecutionInspector::class)->handle(
        $fixture['project']->organization_id,
        $fixture['project']->id,
        $fixture['execution']->id,
    );

    expect($inspector['execution']['status'])->toBe('completed')
        ->and($inspector['simulation'])->toBe([
            'simulated' => true, 'verified' => false, 'evidenceStillRequired' => true,
        ])
        ->and(array_column($inspector['attempts'], 'number'))->toBe([1])
        ->and($inspector['plan'])->toHaveCount(3)
        ->and($inspector['changedFiles'][0])->toHaveKeys(['path', 'change_type', 'summary'])
        ->and($inspector['diffSummary'])->toContain('no repository diff was produced')
        ->and($inspector['validations'])->toHaveCount(2)
        ->and($inspector['repository']['branch']['reference'])->toStartWith('simulation://')
        ->and($inspector['repository']['pullRequest']['target_branch'])->toBe('develop')
        ->and($inspector['evidenceGaps'])->not->toBeEmpty()
        ->and($inspector['lease']['recoveryState'])->toBe('released')
        ->and(array_column($inspector['auditTimeline'], 'sequence'))->toBe(
            collect($inspector['auditTimeline'])->pluck('sequence')->sort()->values()->all(),
        );
});

test('DevelopmentExecutionInspector redacts arbitrary artifact metadata and secret-like values', function (): void {
    $fixture = aios101InspectorFixture();
    $attempt = ExecutionAttempt::query()->where('execution_id', $fixture['execution']->id)->firstOrFail();
    Artifact::factory()->forAttempt($attempt)->create([
        'artifact_type' => 'unknown_provider_payload',
        'name' => 'Unsafe provider payload fixture',
        'metadata' => [
            'api_key' => 'secret-aios-101',
            'provider_response_body' => ['raw' => 'must-not-leak'],
            'exception_trace' => 'private-stack-trace',
        ],
    ]);
    $inspector = app(GetDevelopmentExecutionInspector::class)->handle(
        $fixture['project']->organization_id,
        $fixture['project']->id,
        $fixture['execution']->id,
    );
    $serialized = json_encode($inspector, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toContain('secret-aios-101')
        ->not->toContain('must-not-leak')
        ->not->toContain('private-stack-trace')
        ->and(collect($inspector['artifacts'])->firstWhere('type', 'unknown_provider_payload')['details'])->toBe([]);
});

test('DevelopmentExecutionInspector exposes stable attempt order and retry failure state', function (): void {
    $fixture = aios101InspectorFixture(process: false);
    $execution = $fixture['execution'];
    $execution->forceFill([
        'status' => ExecutionStatus::RetryScheduled,
        'attempt_count' => 2,
        'next_attempt_at' => now()->addMinute(),
        'started_at' => now()->subMinutes(2),
    ])->save();
    ExecutionAttempt::factory()->for($execution)->failed()->create(['attempt_number' => 2]);
    ExecutionAttempt::factory()->for($execution)->failed()->create(['attempt_number' => 1]);

    $inspector = app(GetDevelopmentExecutionInspector::class)->handle(
        $fixture['project']->organization_id,
        $fixture['project']->id,
        $execution->id,
    );

    expect(array_column($inspector['attempts'], 'number'))->toBe([1, 2])
        ->and($inspector['retry']['scheduled'])->toBeTrue()
        ->and($inspector['error']['attemptNumber'])->toBe(2)
        ->and($inspector['missingArtifacts'])->toContain('implementation_plan', 'synthetic_pull_request');
});

test('DevelopmentExecutionInspector controller authorizes a member and loads deferred data', function (): void {
    $fixture = aios101InspectorFixture();
    $user = aios101Member($fixture);

    $this->actingAs($user)
        ->get(route('organizations.projects.development.executions.show', [
            'organization' => $fixture['project']->organization,
            'project' => $fixture['project'],
            'execution' => $fixture['execution']->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/development/executions/show')
            ->where('project.id', $fixture['project']->id)
            ->missing('inspector')
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload
                ->where('inspector.execution.id', $fixture['execution']->id)
                ->where('inspector.simulation.verified', false)));
});

test('DevelopmentExecutionInspector rejects cross-tenant and cross-project execution reads', function (): void {
    $fixture = aios101InspectorFixture();
    $outsider = User::factory()->create();
    $otherOrganization = Organization::factory()->create();
    OrganizationMembership::factory()->for($otherOrganization)->for($outsider)->create();
    $otherFixture = aios101InspectorFixture();
    $member = aios101Member($fixture);

    $this->actingAs($outsider)
        ->get(route('organizations.projects.development.executions.show', [
            'organization' => $fixture['project']->organization,
            'project' => $fixture['project'],
            'execution' => $fixture['execution']->id,
        ]))
        ->assertNotFound();
    $this->actingAs($member)
        ->get(route('organizations.projects.development.executions.show', [
            'organization' => $fixture['project']->organization,
            'project' => $fixture['project'],
            'execution' => $otherFixture['execution']->id,
        ]))
        ->assertNotFound();
});

test('DevelopmentExecutionInspector rejects non-development capabilities', function (): void {
    $fixture = aios101InspectorFixture(process: false);
    $planningExecution = Execution::factory()->for($fixture['project'])->create([
        'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
        'capability' => 'planning.roadmap',
    ]);

    expect(fn (): array => app(GetDevelopmentExecutionInspector::class)->handle(
        $fixture['project']->organization_id,
        $fixture['project']->id,
        $planningExecution->id,
    ))->toThrow(ModelNotFoundException::class);
});
