<?php

declare(strict_types=1);

use App\Application\Events\Data\RealTimeStreamMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

it('builds a project-scoped transport-neutral message', function (): void {
    $eventId = (string) Str::ulid();
    $correlationId = (string) Str::ulid();
    $occurredAt = CarbonImmutable::parse(
        '2026-07-27T08:30:00+08:00',
    );

    $message = new RealTimeStreamMessage(
        eventId: $eventId,
        eventName: 'office.projection_updated',
        organizationId: 10,
        projectId: 25,
        occurredAt: $occurredAt,
        correlationId: $correlationId,
        executionId: 'execution-01',
        schemaVersion: 1,
        data: [
            'agent_state' => 'planning',
        ],
    );

    expect($message->channelName())
        ->toBe('organizations.10.projects.25.stream')
        ->and($message->occurredAt->getTimezone()->getName())
        ->toBe('UTC')
        ->and($message->toArray())
        ->toBe([
            'event_id' => $eventId,
            'event_name' => 'office.projection_updated',
            'organization_id' => 10,
            'project_id' => 25,
            'occurred_at' => $message->occurredAt->toISOString(),
            'correlation_id' => $correlationId,
            'execution_id' => 'execution-01',
            'schema_version' => 1,
            'data' => [
                'agent_state' => 'planning',
            ],
        ]);
});

it('builds an organization-scoped channel when no project is supplied', function (): void {
    $message = new RealTimeStreamMessage(
        eventId: (string) Str::ulid(),
        eventName: 'organization.projection_updated',
        organizationId: 10,
        projectId: null,
        occurredAt: CarbonImmutable::now(),
        correlationId: (string) Str::ulid(),
        executionId: null,
        schemaVersion: 1,
        data: [],
    );

    expect($message->channelName())
        ->toBe('organizations.10.stream');
});

it('rejects invalid event names and non-object data', function (): void {
    expect(
        fn (): RealTimeStreamMessage => new RealTimeStreamMessage(
            eventId: (string) Str::ulid(),
            eventName: 'Invalid Event Name',
            organizationId: 10,
            projectId: null,
            occurredAt: CarbonImmutable::now(),
            correlationId: (string) Str::ulid(),
            executionId: null,
            schemaVersion: 1,
            data: [],
        ),
    )->toThrow(
        InvalidArgumentException::class,
        'The real-time stream event name must use lowercase dotted notation.',
    );

    expect(
        fn (): RealTimeStreamMessage => new RealTimeStreamMessage(
            eventId: (string) Str::ulid(),
            eventName: 'office.projection_updated',
            organizationId: 10,
            projectId: null,
            occurredAt: CarbonImmutable::now(),
            correlationId: (string) Str::ulid(),
            executionId: null,
            schemaVersion: 1,
            data: [
                'not-object-shaped',
            ],
        ),
    )->toThrow(
        InvalidArgumentException::class,
        'The real-time stream data must use string keys.',
    );
});

it('rejects arbitrary objects from client-facing data', function (): void {
    expect(
        fn (): RealTimeStreamMessage => new RealTimeStreamMessage(
            eventId: (string) Str::ulid(),
            eventName: 'office.projection_updated',
            organizationId: 10,
            projectId: 25,
            occurredAt: CarbonImmutable::now(),
            correlationId: (string) Str::ulid(),
            executionId: null,
            schemaVersion: 1,
            data: [
                'unsafe' => new stdClass,
            ],
        ),
    )->toThrow(
        InvalidArgumentException::class,
        'The real-time stream data value [unsafe] is not JSON-safe.',
    );
});
