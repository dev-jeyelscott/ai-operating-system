<?php

declare(strict_types=1);

use App\Application\Shared\Exceptions\ConflictException;
use App\Application\Tickets\ReconcileTicketExternalState;
use App\Domain\Audit\AuditEventType;
use App\Domain\Tickets\TicketActualState;
use App\Domain\Tickets\TicketExternalStateSource;
use App\Domain\Tickets\TicketStatus;
use App\Models\AuditEvent;
use App\Models\OutboxMessage;
use App\Models\RoadmapTask;
use Tests\Support\TicketTestFixture;

test('notion reconciliation updates only external state and preserves authoritative timestamps', function (): void {
    $fixture = TicketTestFixture::create(ticketAttributes: [
        'status' => TicketStatus::Ready,
        'desired_state' => TicketStatus::Ready,
        'status_changed_at' => now()->subHour(),
        'ready_at' => now()->subHour(),
    ]);
    $originalChangedAt = $fixture['ticket']->status_changed_at;
    $originalReadyAt = $fixture['ticket']->ready_at;

    $ticket = app(ReconcileTicketExternalState::class)->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        source: TicketExternalStateSource::Notion,
        idempotencyKey: 'reconcile-notion-1',
        actorId: 'notion-reconciler',
        correlationId: 'correlation-089-reconcile',
        desiredState: TicketStatus::InProgress,
        reportedState: 'For QA',
    );

    expect($ticket->status)->toBe(TicketStatus::Ready)
        ->and($ticket->desired_state)->toBe(TicketStatus::InProgress)
        ->and($ticket->reported_state)->toBe('for_qa')
        ->and($ticket->observed_state)->toBeNull()
        ->and($ticket->actual_state)->toBe(TicketActualState::Unverified)
        ->and($ticket->status_changed_at->equalTo($originalChangedAt))->toBeTrue()
        ->and($ticket->ready_at?->equalTo($originalReadyAt))->toBeTrue();

    $audit = AuditEvent::query()
        ->where('event_type', AuditEventType::TicketExternalStateReconciled->value)
        ->firstOrFail();

    expect($audit->metadata['source'])->toBe('notion')
        ->and($audit->metadata['observed_at'])->toBeString()
        ->and(OutboxMessage::query()
            ->where('event_name', AuditEventType::TicketExternalStateReconciled->value)
            ->count())->toBe(1);
});

test('deterministic observation cannot promote a simulated ticket to verified', function (): void {
    $fixture = TicketTestFixture::create();

    $ticket = app(ReconcileTicketExternalState::class)->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        source: TicketExternalStateSource::DeterministicObserver,
        idempotencyKey: 'reconcile-observed-1',
        actorId: 'simulation-observer',
        correlationId: 'correlation-089-observed-1',
        observedState: 'Synthetic Commit Created',
    );

    expect($ticket->observed_state)->toBe('synthetic_commit_created')
        ->and($ticket->actual_state)->toBe(TicketActualState::Unverified);
});

test('reconciliation rejects fields outside the source policy', function (): void {
    $fixture = TicketTestFixture::create();

    expect(fn (): RoadmapTask => app(ReconcileTicketExternalState::class)->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        source: TicketExternalStateSource::ExecutionProvider,
        idempotencyKey: 'reconcile-provider-invalid',
        actorId: 'simulation-provider',
        correlationId: 'correlation-089-provider-invalid',
        observedState: 'tests passed',
    ))->toThrow(InvalidArgumentException::class);
});

test('reconciliation rejects malformed external state', function (string $state): void {
    $fixture = TicketTestFixture::create();

    expect(fn (): RoadmapTask => app(ReconcileTicketExternalState::class)->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        source: TicketExternalStateSource::ExecutionProvider,
        idempotencyKey: 'reconcile-malformed-'.hash('sha256', $state),
        actorId: 'simulation-provider',
        correlationId: 'correlation-089-malformed',
        reportedState: $state,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'blank' => '   ',
    'single character' => 'x',
    'unsupported symbols' => '🚀',
    'oversized' => str_repeat('a', 121),
]);

test('an exact reconciliation replay is idempotent and payload drift fails closed', function (): void {
    $fixture = TicketTestFixture::create();
    $service = app(ReconcileTicketExternalState::class);
    $arguments = [
        'organizationId' => $fixture['project']->organization_id,
        'projectId' => $fixture['project']->id,
        'roadmapId' => $fixture['roadmap']->id,
        'ticketId' => $fixture['ticket']->id,
        'source' => TicketExternalStateSource::ExecutionProvider,
        'idempotencyKey' => 'reconcile-replay',
        'actorId' => 'simulation-provider',
        'correlationId' => 'correlation-089-replay',
        'reportedState' => 'validation passed',
    ];

    $first = $service->handle(...$arguments);
    $second = $service->handle(...$arguments);

    expect($second->is($first))->toBeTrue()
        ->and(AuditEvent::query()
            ->where('event_type', AuditEventType::TicketExternalStateReconciled->value)
            ->count())->toBe(1)
        ->and(OutboxMessage::query()
            ->where('event_name', AuditEventType::TicketExternalStateReconciled->value)
            ->count())->toBe(1);

    $arguments['reportedState'] = 'validation failed';

    expect(fn (): RoadmapTask => $service->handle(...$arguments))
        ->toThrow(ConflictException::class);
});
