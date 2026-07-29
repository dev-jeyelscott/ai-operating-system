<?php

declare(strict_types=1);

use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\SelectNextTicketAndAcquireLease;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketStatus;
use App\Models\Execution;
use App\Models\ProjectContextSnapshot;
use Tests\Support\TicketTestFixture;

/**
 * Create an approved selection fixture and its queued development execution.
 *
 * @return array<string, mixed>
 */
function aios092SelectionFixture(array $executionAttributes = []): array
{
    $fixture = TicketTestFixture::create(ticketAttributes: [
        'status' => TicketStatus::Ready,
        'desired_state' => TicketStatus::Ready,
        'status_changed_at' => now()->subMinute(),
        'ready_at' => now()->subMinute(),
    ]);
    $fixture['roadmap']->forceFill([
        'status' => 'approved',
        'approved_fingerprint' => $fixture['roadmap']->candidate_fingerprint,
        'approved_snapshot' => ['schema_version' => 1],
        'approved_at' => now(),
    ])->save();
    $fixture['execution'] = Execution::factory()
        ->for($fixture['project'])
        ->create(array_merge([
            'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
            'capability' => 'development.simulation',
        ], $executionAttributes));

    return $fixture;
}

/**
 * Run the selector for one prepared fixture.
 *
 * @param  array<string, mixed>  $fixture
 */
function aios092Select(array $fixture): void
{
    app(SelectNextTicketAndAcquireLease::class)->handle(
        new TicketSelectionRequest(
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            executionId: $fixture['execution']->id,
            owner: 'aios-092-feature-worker',
        ),
    );
}

test('selector rejects unsupported and non-startable executions before selection', function (
    array $executionAttributes,
): void {
    $fixture = aios092SelectionFixture($executionAttributes);

    expect(fn () => aios092Select($fixture))->toThrow(LogicException::class);
    $this->assertDatabaseCount('ticket_execution_leases', 0);
    $this->assertDatabaseCount('outbox_messages', 0);
    $this->assertDatabaseCount('audit_events', 0);
})->with([
    'planning capability' => [[
        'capability' => 'planning.roadmap',
    ]],
    'running lifecycle' => [[
        'status' => ExecutionStatus::Running,
        'started_at' => '2026-07-29 00:00:00',
    ]],
    'waiting lifecycle' => [[
        'status' => ExecutionStatus::WaitingForApproval,
    ]],
    'retry scheduled lifecycle' => [[
        'status' => ExecutionStatus::RetryScheduled,
        'next_attempt_at' => '2026-07-29 00:05:00',
    ]],
    'completed lifecycle' => [[
        'status' => ExecutionStatus::Completed,
        'started_at' => '2026-07-29 00:00:00',
        'finished_at' => '2026-07-29 00:01:00',
    ]],
    'failed lifecycle' => [[
        'status' => ExecutionStatus::Failed,
        'started_at' => '2026-07-29 00:00:00',
        'finished_at' => '2026-07-29 00:01:00',
    ]],
    'cancelled lifecycle' => [[
        'status' => ExecutionStatus::Cancelled,
        'cancel_requested_at' => '2026-07-29 00:00:00',
        'cancelled_at' => '2026-07-29 00:01:00',
        'finished_at' => '2026-07-29 00:01:00',
    ]],
    'cancel requested' => [[
        'cancel_requested_at' => '2026-07-29 00:00:00',
    ]],
]);

test('selector ignores an approved roadmap from another context lineage', function (): void {
    $fixture = aios092SelectionFixture();
    $otherContext = ProjectContextSnapshot::query()->create([
        'project_id' => $fixture['project']->id,
        'project_configuration_version_id' => $fixture['configurationVersion']->id,
        'configuration_revision' => 1,
        'identity_schema_version' => 1,
        'approved_document_set_fingerprint' => hash('sha256', 'other-context'),
        'approved_document_versions' => [],
    ]);
    $fixture['execution'] = Execution::factory()
        ->for($fixture['project'])
        ->create([
            'project_context_snapshot_id' => $otherContext->id,
            'capability' => 'development.simulation',
        ]);

    $result = app(SelectNextTicketAndAcquireLease::class)->handle(
        new TicketSelectionRequest(
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            executionId: $fixture['execution']->id,
            owner: 'aios-092-feature-worker',
        ),
    );

    expect($result->isSelected())->toBeFalse();
    $this->assertDatabaseCount('ticket_execution_leases', 0);
});
