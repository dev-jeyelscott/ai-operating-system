<?php

declare(strict_types=1);

use App\Application\Events\Data\StoredDomainEvent;
use App\Application\Tickets\Consumers\ReleaseLeaseForTerminalExecution;
use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\SelectNextTicketAndAcquireLease;
use App\Application\Tickets\TicketLeaseManager;
use App\Domain\Approvals\ApprovalType;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketLeaseReleaseReason;
use App\Domain\Tickets\TicketStatus;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\TicketExecutionLease;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Tests\Support\TicketTestFixture;

/** @return array<string, mixed> */
function aios093LeaseFixture(): array
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
    $execution = Execution::factory()->for($fixture['project'])->create([
        'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
        'capability' => 'development.simulation',
    ]);
    $result = app(SelectNextTicketAndAcquireLease::class)->handle(
        new TicketSelectionRequest(
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            executionId: $execution->id,
            owner: 'aios-093-worker',
        ),
    );

    $fixture['execution'] = $execution;
    $fixture['lease'] = TicketExecutionLease::query()->findOrFail($result->leaseId);

    return $fixture;
}

test('heartbeat uses server time and preserves the lease interval', function (): void {
    CarbonImmutable::setTestNow('2026-07-29 12:00:00.000000');
    $fixture = aios093LeaseFixture();
    $originalHeartbeat = $fixture['lease']->heartbeat_at;
    $originalInterval = $originalHeartbeat->diffInMicroseconds($fixture['lease']->expires_at);
    CarbonImmutable::setTestNow('2026-07-29 12:01:00.123456');

    $lease = app(TicketLeaseManager::class)->heartbeat(
        $fixture['project']->organization_id,
        $fixture['project']->id,
        $fixture['lease']->id,
        $fixture['execution']->id,
        'aios-093-worker',
    );

    expect($lease->heartbeat_at->format('Y-m-d H:i:s.u'))->toBe('2026-07-29 12:01:00.123456')
        ->and($lease->heartbeat_at->greaterThan($originalHeartbeat))->toBeTrue()
        ->and($lease->heartbeat_at->diffInMicroseconds($lease->expires_at))->toBe($originalInterval);
    $this->assertDatabaseHas('outbox_messages', ['event_name' => 'ticket.lease_heartbeat']);
    $this->assertDatabaseHas('audit_events', ['event_type' => 'ticket.lease_heartbeat']);
    CarbonImmutable::setTestNow();
});

test('heartbeat rejects wrong owner released lease and terminal execution', function (string $case): void {
    CarbonImmutable::setTestNow('2026-07-29 12:00:00');
    $fixture = aios093LeaseFixture();

    if ($case === 'released') {
        $fixture['execution']->forceFill([
            'status' => ExecutionStatus::Completed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ])->save();
        app(TicketLeaseManager::class)->releaseForExecution(
            $fixture['project']->organization_id,
            $fixture['project']->id,
            $fixture['lease']->id,
            $fixture['execution']->id,
            'aios-093-worker',
            TicketLeaseReleaseReason::Completion,
        );
    } elseif ($case === 'terminal') {
        $fixture['execution']->forceFill([
            'status' => ExecutionStatus::Failed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ])->save();
    } elseif ($case === 'expired') {
        CarbonImmutable::setTestNow('2026-07-29 12:10:00');
    }

    expect(fn () => app(TicketLeaseManager::class)->heartbeat(
        $fixture['project']->organization_id,
        $fixture['project']->id,
        $fixture['lease']->id,
        $fixture['execution']->id,
        $case === 'owner' ? 'another-worker' : 'aios-093-worker',
    ))->toThrow(LogicException::class);
    CarbonImmutable::setTestNow();
})->with(['owner', 'released', 'terminal', 'expired']);

test('terminal release is idempotent and conflicting reason fails closed', function (): void {
    $fixture = aios093LeaseFixture();
    $fixture['execution']->forceFill([
        'status' => ExecutionStatus::Completed,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ])->save();
    $manager = app(TicketLeaseManager::class);

    $first = $manager->releaseForExecution(
        $fixture['project']->organization_id,
        $fixture['project']->id,
        $fixture['lease']->id,
        $fixture['execution']->id,
        'aios-093-worker',
        TicketLeaseReleaseReason::Completion,
    );
    $second = $manager->releaseForExecution(
        $fixture['project']->organization_id,
        $fixture['project']->id,
        $fixture['lease']->id,
        $fixture['execution']->id,
        'aios-093-worker',
        TicketLeaseReleaseReason::Completion,
    );

    expect($second->released_at)->toEqual($first->released_at);
    $this->assertDatabaseCount('ticket_execution_leases', 1);
    $this->assertDatabaseCount('outbox_messages', 3);
    $this->assertDatabaseCount('audit_events', 3);

    expect(fn () => $manager->releaseForExecution(
        $fixture['project']->organization_id,
        $fixture['project']->id,
        $fixture['lease']->id,
        $fixture['execution']->id,
        'aios-093-worker',
        TicketLeaseReleaseReason::TerminalFailure,
    ))->toThrow(LogicException::class);
});

test('recovery releases expired terminal leases but keeps live leases', function (
    ExecutionStatus $status,
    int $expected,
): void {
    CarbonImmutable::setTestNow('2026-07-29 12:00:00');
    $fixture = aios093LeaseFixture();
    $attributes = ['status' => $status];

    if ($status->isTerminal()) {
        $attributes['started_at'] = now()->subMinutes(10);
        $attributes['finished_at'] = now()->subMinutes(5);
    }

    $fixture['execution']->forceFill($attributes)->save();
    CarbonImmutable::setTestNow('2026-07-29 12:10:00');

    expect(app(TicketLeaseManager::class)->recoverExpired())->toBe($expected)
        ->and($fixture['lease']->refresh()->isActive())->toBe($expected === 0);
    CarbonImmutable::setTestNow();
})->with([
    'running remains live' => [ExecutionStatus::Running, 0],
    'retry scheduled remains live' => [ExecutionStatus::RetryScheduled, 0],
    'blocked needs approval' => [ExecutionStatus::Blocked, 0],
    'completed releases' => [ExecutionStatus::Completed, 1],
    'failed releases' => [ExecutionStatus::Failed, 1],
    'cancelled releases' => [ExecutionStatus::Cancelled, 1],
]);

test('postgresql rejects an unsupported release reason', function (): void {
    $fixture = aios093LeaseFixture();

    expect(fn () => TicketExecutionLease::query()
        ->whereKey($fixture['lease']->id)
        ->update([
            'released_at' => now(),
            'release_reason' => 'expired_only',
        ]))->toThrow(QueryException::class);
});

test('approved manual recovery releases one expired blocked lease', function (): void {
    CarbonImmutable::setTestNow('2026-07-29 12:00:00');
    $fixture = aios093LeaseFixture();
    $fixture['execution']->forceFill(['status' => ExecutionStatus::Blocked])->save();
    Approval::factory()->approved()->for($fixture['project'])->create([
        'type' => ApprovalType::Execution,
        'request_payload' => [
            'action' => 'manual_recovery',
            'execution_id' => $fixture['execution']->id,
            'lease_id' => $fixture['lease']->id,
        ],
    ]);
    CarbonImmutable::setTestNow('2026-07-29 12:10:00');

    expect(app(TicketLeaseManager::class)->recoverExpired())->toBe(1)
        ->and(app(TicketLeaseManager::class)->recoverExpired())->toBe(0)
        ->and($fixture['lease']->refresh()->release_reason)->toBe(TicketLeaseReleaseReason::ManualRecovery);
    CarbonImmutable::setTestNow();
});

test('terminal execution consumer releases the lease once', function (): void {
    $fixture = aios093LeaseFixture();
    $fixture['execution']->forceFill([
        'status' => ExecutionStatus::Completed,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ])->save();
    $event = new StoredDomainEvent(
        eventId: '01KYPHASE7TERMINALEVENT000',
        eventName: 'execution.attempt.completed',
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        schemaVersion: 1,
        envelope: ['payload' => ['execution_id' => $fixture['execution']->id]],
    );
    $consumer = app(ReleaseLeaseForTerminalExecution::class);

    $consumer->handle($event);
    $consumer->handle($event);

    expect($fixture['lease']->refresh()->release_reason)->toBe(TicketLeaseReleaseReason::Completion);
    $this->assertDatabaseCount('ticket_execution_leases', 1);
    $this->assertDatabaseCount('outbox_messages', 3);
});

test('execution recovery command reports lease recovery after resilience passes', function (): void {
    $this->artisan('executions:recover', ['--limit' => 1])
        ->expectsOutput('Timed out 0 attempt(s); released 0 retry execution(s); recovered 0 ticket lease(s).')
        ->assertSuccessful();
});
