<?php

declare(strict_types=1);

use App\Application\Development\ListProjectDevelopmentQueue;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Tickets\TicketStatus;
use App\Models\Execution;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RoadmapTask;
use App\Models\TaskDependency;
use App\Models\TicketExecutionLease;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TicketTestFixture;

/** @return array<string, mixed> */
function aios100QueueFixture(): array
{
    $fixture = TicketTestFixture::create(ticketAttributes: [
        'stable_id' => 'AIOS-100-A', 'title' => 'First workable ticket',
        'status' => TicketStatus::Ready, 'desired_state' => TicketStatus::Ready,
        'status_changed_at' => now()->subMinutes(5), 'ready_at' => now()->subMinutes(5),
        'position' => 2, 'priority' => 'high',
    ]);
    $fixture['roadmap']->forceFill([
        'status' => 'approved', 'approved_at' => now(),
        'approved_fingerprint' => hash('sha256', 'approved-roadmap'),
        'approved_snapshot' => ['schema_version' => 1],
    ])->save();
    $first = $fixture['ticket'];
    $blocked = RoadmapTask::query()->create([
        'roadmap_id' => $fixture['roadmap']->id,
        'roadmap_phase_id' => $first->roadmap_phase_id,
        'roadmap_milestone_id' => $first->roadmap_milestone_id,
        'stable_id' => 'AIOS-100-B', 'title' => 'Blocked ticket',
        'objective' => 'Explain every stable blocking reason.', 'ticket_type' => 'feature',
        'scope' => ['included' => [], 'excluded' => []], 'acceptance_criteria' => [],
        'source_references' => [], 'evidence_requirements' => [], 'priority' => 'medium',
        'risk' => 'medium', 'reasoning_level' => 'high', 'reasoning' => 'Blocked.',
        'logical_agent' => 'backend_engineer', 'estimated_complexity' => 3,
        'human_approval_required' => true, 'position' => 1, 'is_critical_path' => false,
        'status' => TicketStatus::Blocked, 'desired_state' => TicketStatus::Ready,
        'reported_state' => 'blocked', 'observed_state' => 'blocked',
        'status_changed_at' => now()->subMinutes(10),
    ]);
    TaskDependency::query()->create([
        'roadmap_id' => $fixture['roadmap']->id,
        'roadmap_task_id' => $blocked->id,
        'depends_on_task_id' => $first->id,
    ]);

    return [...$fixture, 'first' => $first, 'blocked' => $blocked];
}

function aios100Member(array $fixture): User
{
    $user = User::factory()->create();
    OrganizationMembership::factory()
        ->for($fixture['project']->organization)
        ->for($user)
        ->create();

    return $user;
}

test('DevelopmentQueue returns workable and ineligible tickets in their required deterministic orders', function (): void {
    $fixture = aios100QueueFixture();
    $queue = app(ListProjectDevelopmentQueue::class)->handle(
        $fixture['project']->organization_id,
        $fixture['project']->id,
    );

    expect(array_column($queue['workable'], 'id'))->toBe(['AIOS-100-A'])
        ->and(array_column($queue['ineligible'], 'id'))->toBe(['AIOS-100-B'])
        ->and($queue['ineligible'][0]['ineligibilityReasonCodes'])->toBe([
            'status_not_eligible', 'dependency_incomplete', 'blocker_unresolved', 'approval_missing',
        ])
        ->and($queue['metadata']['approvedRoadmapId'])->toBe($fixture['roadmap']->id)
        ->and($queue['metadata']['approvedRoadmapRevision'])->toBe(1)
        ->and($queue['metadata']['noWorkableTicket'])->toBeFalse()
        ->and($queue['metadata']['queueFingerprint'])->toMatch('/\A[a-f0-9]{64}\z/');
});

test('DevelopmentQueue excludes an active lease and changes the queue fingerprint', function (): void {
    $fixture = aios100QueueFixture();
    $service = app(ListProjectDevelopmentQueue::class);
    $before = $service->handle($fixture['project']->organization_id, $fixture['project']->id);
    $execution = Execution::factory()->for($fixture['project'])->create([
        'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
        'capability' => 'development.simulation',
        'requested_reasoning_level' => ReasoningLevel::High,
        'status' => ExecutionStatus::Running,
    ]);
    $now = CarbonImmutable::now();
    TicketExecutionLease::query()->create([
        'project_id' => $fixture['project']->id, 'roadmap_task_id' => $fixture['first']->id,
        'execution_id' => $execution->id, 'owner' => 'queue-test-worker',
        'acquired_at' => $now, 'heartbeat_at' => $now, 'expires_at' => $now->addMinute(),
    ]);
    $after = $service->handle($fixture['project']->organization_id, $fixture['project']->id);

    expect($after['workable'])->toBe([])
        ->and($after['metadata']['noWorkableTicket'])->toBeTrue()
        ->and($after['metadata']['queueFingerprint'])->not->toBe($before['metadata']['queueFingerprint'])
        ->and($after['activeLeases'])->toHaveCount(1)
        ->and(array_keys($after['activeLeases'][0]))->toBe(['ticketId', 'expiresAt', 'expired'])
        ->and($after['ineligible'][1]['inspectorExecutionId'])->toBe($execution->id)
        ->and($after['ineligible'][1]['ineligibilityReasonCodes'])->toContain('active_lease');
});

test('DevelopmentQueue no-work state is successful and neutral', function (): void {
    $fixture = aios100QueueFixture();
    $fixture['first']->applyAuthoritativeStatusTransition(TicketStatus::Done, CarbonImmutable::now());
    $user = aios100Member($fixture);

    $this->actingAs($user)
        ->get(route('organizations.projects.development.index', [
            'organization' => $fixture['project']->organization,
            'project' => $fixture['project'],
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/development/index')
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload
                ->where('queue.metadata.noWorkableTicket', true)
                ->has('queue.ineligible', 2)
                ->has('leases', 0)));
});

test('DevelopmentQueue page authorizes a project member and defers queue loading', function (): void {
    $fixture = aios100QueueFixture();
    $user = aios100Member($fixture);

    $this->actingAs($user)
        ->get(route('organizations.projects.development.index', [
            'organization' => $fixture['project']->organization,
            'project' => $fixture['project'],
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/development/index')
            ->where('organization.id', $fixture['project']->organization_id)
            ->where('project.id', $fixture['project']->id)
            ->missing('queue')
            ->missing('leases'));
});

test('DevelopmentQueue prevents cross-organization and cross-project reads', function (): void {
    $fixture = aios100QueueFixture();
    $outsider = User::factory()->create();
    $otherOrganization = Organization::factory()->create();
    OrganizationMembership::factory()->for($otherOrganization)->for($outsider)->create();

    $this->actingAs($outsider)
        ->get(route('organizations.projects.development.index', [
            'organization' => $fixture['project']->organization,
            'project' => $fixture['project'],
        ]))
        ->assertNotFound();
    $this->actingAs($outsider)
        ->get(route('organizations.projects.development.index', [
            'organization' => $otherOrganization,
            'project' => $fixture['project'],
        ]))
        ->assertNotFound();
});

test('DevelopmentQueue uses a bounded query count as tickets are added', function (): void {
    $fixture = aios100QueueFixture();
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(ListProjectDevelopmentQueue::class)->handle($fixture['project']->organization_id, $fixture['project']->id);
    $firstCount = count(DB::getQueryLog());

    RoadmapTask::query()->create([
        'roadmap_id' => $fixture['roadmap']->id, 'roadmap_phase_id' => $fixture['first']->roadmap_phase_id,
        'roadmap_milestone_id' => $fixture['first']->roadmap_milestone_id, 'stable_id' => 'AIOS-100-C',
        'title' => 'Another ticket', 'objective' => 'Prove bounded reads.', 'ticket_type' => 'feature',
        'scope' => ['included' => [], 'excluded' => []], 'acceptance_criteria' => [],
        'source_references' => [], 'evidence_requirements' => [], 'priority' => 'low', 'risk' => 'low',
        'reasoning_level' => 'medium', 'reasoning' => 'Query guard.', 'logical_agent' => 'backend_engineer',
        'estimated_complexity' => 1, 'human_approval_required' => false, 'position' => 3,
        'is_critical_path' => false, 'status' => TicketStatus::Ready, 'desired_state' => TicketStatus::Ready,
        'status_changed_at' => now(), 'ready_at' => now(),
    ]);
    DB::flushQueryLog();
    app(ListProjectDevelopmentQueue::class)->handle($fixture['project']->organization_id, $fixture['project']->id);
    $secondCount = count(DB::getQueryLog());

    expect($secondCount)->toBeLessThanOrEqual($firstCount + 1);
});
