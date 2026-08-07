<?php

declare(strict_types=1);

use App\Application\Audit\Contracts\AuditEventRepository;
use App\Application\Audit\Data\AuditEventData;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Application\Shared\Exceptions\ConflictException;
use App\Application\Tickets\TransitionTicketStatus;
use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Approvals\ApprovalType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Events\DomainEventEnvelope;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketStatus;
use App\Models\Approval;
use App\Models\AuditEvent;
use App\Models\Execution;
use App\Models\OutboxMessage;
use App\Models\RoadmapTask;
use Tests\Support\TicketTestFixture;

test('authoritative status transitions set timestamps and record events atomically', function (): void {
    $fixture = TicketTestFixture::create();
    $fixture['ticket']->refresh();
    $originalChangedAt = $fixture['ticket']->status_changed_at;

    $ticket = app(TransitionTicketStatus::class)->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        target: TicketStatus::Ready,
        idempotencyKey: 'transition-ready-1',
        actorId: 'development-engine',
        correlationId: 'correlation-089-1',
    );

    expect($ticket->status)->toBe(TicketStatus::Ready)
        ->and($ticket->status_changed_at->equalTo($originalChangedAt))->toBeFalse()
        ->and($ticket->ready_at)->not->toBeNull();

    expect(OutboxMessage::query()
        ->where('event_name', AuditEventType::TicketStatusTransitioned->value)
        ->count())->toBe(1)
        ->and(AuditEvent::query()
            ->where('event_type', AuditEventType::TicketStatusTransitioned->value)
            ->count())->toBe(1);
});

test('an exact transition replay returns the current row without duplicate events', function (): void {
    $fixture = TicketTestFixture::create();
    $service = app(TransitionTicketStatus::class);
    $arguments = [
        'organizationId' => $fixture['project']->organization_id,
        'projectId' => $fixture['project']->id,
        'roadmapId' => $fixture['roadmap']->id,
        'ticketId' => $fixture['ticket']->id,
        'target' => TicketStatus::Ready,
        'idempotencyKey' => 'transition-ready-replay',
        'actorId' => 'development-engine',
        'correlationId' => 'correlation-089-replay',
    ];

    $first = $service->handle(...$arguments);
    $second = $service->handle(...$arguments);

    expect($second->is($first))->toBeTrue()
        ->and(OutboxMessage::query()
            ->where('event_name', AuditEventType::TicketStatusTransitioned->value)
            ->count())->toBe(1)
        ->and(AuditEvent::query()
            ->where('event_type', AuditEventType::TicketStatusTransitioned->value)
            ->count())->toBe(1);
});

test('transition idempotency payload drift fails closed', function (): void {
    $fixture = TicketTestFixture::create();
    $service = app(TransitionTicketStatus::class);

    $service->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        target: TicketStatus::Ready,
        idempotencyKey: 'transition-payload-drift',
        actorId: 'development-engine',
        correlationId: 'correlation-089-drift',
    );

    expect(fn (): RoadmapTask => $service->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        target: TicketStatus::InProgress,
        idempotencyKey: 'transition-payload-drift',
        actorId: 'development-engine',
        correlationId: 'correlation-089-drift',
    ))->toThrow(ConflictException::class);
});

test('changes requested requires an approved execution decision', function (): void {
    $fixture = TicketTestFixture::create(ticketAttributes: [
        'status' => TicketStatus::ChangesRequested,
    ]);
    $service = app(TransitionTicketStatus::class);

    expect(fn (): RoadmapTask => $service->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        target: TicketStatus::InProgress,
        idempotencyKey: 'changes-requested-without-approval',
        actorId: 'development-engine',
        correlationId: 'correlation-089-approval-1',
    ))->toThrow(ConflictException::class);

    Approval::factory()->approved()->for($fixture['project'])->create([
        'type' => ApprovalType::Execution,
        'status' => ApprovalStatus::Approved,
        'request_payload' => [
            'roadmap_task_id' => $fixture['ticket']->id,
            'ticket_id' => $fixture['ticket']->stable_id,
        ],
    ]);

    $ticket = $service->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        target: TicketStatus::InProgress,
        idempotencyKey: 'changes-requested-with-approval',
        actorId: 'development-engine',
        correlationId: 'correlation-089-approval-2',
    );

    expect($ticket->status)->toBe(TicketStatus::InProgress);
});

test('for qa requires a completed uncancelled development execution', function (): void {
    $fixture = TicketTestFixture::create(ticketAttributes: [
        'status' => TicketStatus::InProgress,
    ]);
    $execution = Execution::factory()->completed()->for($fixture['project'])->create([
        'capability' => 'development.simulation',
        'status' => ExecutionStatus::Completed,
    ]);

    $ticket = app(TransitionTicketStatus::class)->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        target: TicketStatus::ForQa,
        idempotencyKey: 'transition-for-qa',
        actorId: 'development-engine',
        correlationId: 'correlation-089-for-qa',
        executionId: $execution->id,
    );

    expect($ticket->status)->toBe(TicketStatus::ForQa);
});

test('cancellation pending wins over a for qa transition', function (): void {
    $fixture = TicketTestFixture::create(ticketAttributes: [
        'status' => TicketStatus::InProgress,
    ]);
    $execution = Execution::factory()->completed()->for($fixture['project'])->create([
        'capability' => 'development.simulation',
        'status' => ExecutionStatus::Completed,
        'cancel_requested_at' => now(),
    ]);

    expect(fn (): RoadmapTask => app(TransitionTicketStatus::class)->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        target: TicketStatus::ForQa,
        idempotencyKey: 'transition-for-qa-cancelled',
        actorId: 'development-engine',
        correlationId: 'correlation-089-for-qa-cancelled',
        executionId: $execution->id,
    ))->toThrow(ConflictException::class);

    expect($fixture['ticket']->refresh()->status)->toBe(TicketStatus::InProgress);
});

test('arbitrary model status assignment is rejected', function (): void {
    $ticket = TicketTestFixture::create()['ticket'];

    expect(function () use ($ticket): void {
        $ticket->forceFill([
            'status' => TicketStatus::Done,
            'status_changed_at' => now(),
        ])->save();
    })->toThrow(LogicException::class);
});

test('event persistence failure rolls back the ticket transition', function (): void {
    $fixture = TicketTestFixture::create();

    app()->instance(DomainEventOutbox::class, new class implements DomainEventOutbox
    {
        public function append(DomainEventEnvelope $event): void
        {
            throw new LogicException('Injected ticket event failure.');
        }
    });

    expect(fn (): RoadmapTask => app(TransitionTicketStatus::class)->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        target: TicketStatus::Ready,
        idempotencyKey: 'transition-event-failure',
        actorId: 'development-engine',
        correlationId: 'correlation-089-failure',
    ))->toThrow(LogicException::class, 'Injected ticket event failure.');

    expect($fixture['ticket']->refresh()->status)->toBe(TicketStatus::Backlog)
        ->and(AuditEvent::query()
            ->where('event_type', AuditEventType::TicketStatusTransitioned->value)
            ->count())->toBe(0);
});

test('audit persistence failure rolls back the ticket and outbox event', function (): void {
    $fixture = TicketTestFixture::create();

    app()->instance(AuditEventRepository::class, new class implements AuditEventRepository
    {
        public function append(AuditEventData $event): void
        {
            throw new LogicException('Injected ticket audit failure.');
        }
    });

    expect(fn (): RoadmapTask => app(TransitionTicketStatus::class)->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapId: $fixture['roadmap']->id,
        ticketId: $fixture['ticket']->id,
        target: TicketStatus::Ready,
        idempotencyKey: 'transition-audit-failure',
        actorId: 'development-engine',
        correlationId: 'correlation-089-audit',
    ))->toThrow(LogicException::class, 'Injected ticket audit failure.');

    expect($fixture['ticket']->refresh()->status)->toBe(TicketStatus::Backlog)
        ->and(OutboxMessage::query()
            ->where('event_name', AuditEventType::TicketStatusTransitioned->value)
            ->count())->toBe(0)
        ->and(AuditEvent::query()
            ->where('event_type', AuditEventType::TicketStatusTransitioned->value)
            ->count())->toBe(0);
});
