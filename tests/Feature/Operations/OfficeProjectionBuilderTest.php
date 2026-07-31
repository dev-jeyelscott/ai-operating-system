<?php

declare(strict_types=1);

use App\Application\Events\Data\StoredDomainEvent;
use App\Application\Operations\BuildOfficeProjection;
use App\Application\Operations\Consumers\RefreshOfficeProjection;
use App\Domain\Audit\AuditEventType;
use App\Domain\Executions\ExecutionStatus;
use App\Models\Execution;
use App\Models\OfficeProjection;
use App\Models\OutboxMessage;
use App\Models\Roadmap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CompletedQualityAssuranceFixture;
use Tests\Support\TicketTestFixture;

uses(RefreshDatabase::class);

/**
 * Mark one generated roadmap as the approved project roadmap.
 */
function approveOfficeProjectionBuilderRoadmap(
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

/**
 * Return one row from a keyed projection list.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function officeProjectionBuilderRow(
    array $rows,
    string $key,
): array {
    foreach ($rows as $row) {
        if (($row['key'] ?? null) === $key) {
            return $row;
        }
    }

    throw new RuntimeException(
        "Office projection row [{$key}] was not found.",
    );
}

it('projects authoritative operations into rooms agents and indicators', function (): void {
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-118-PROJECTION',
    );

    approveOfficeProjectionBuilderRoadmap($fixture['roadmap']);

    $project = $fixture['project'];

    $execution = Execution::factory()
        ->for($project)
        ->create([
            'capability' => 'development.simulation',
            'logical_role' => 'backend_engineer',
            'status' => ExecutionStatus::Running,
            'started_at' => now(),
        ]);

    $first = app(BuildOfficeProjection::class)->handle(
        organizationId: $project->organization_id,
        projectId: $project->id,
    );

    $second = app(BuildOfficeProjection::class)->handle(
        organizationId: $project->organization_id,
        projectId: $project->id,
    );

    $rooms = $first->state['rooms'] ?? null;
    $agents = $first->state['agents'] ?? null;
    $simulation = $first->state['simulation'] ?? null;

    if (! is_array($rooms) || ! is_array($agents) || ! is_array($simulation)) {
        throw new RuntimeException(
            'The office projection contract is malformed.',
        );
    }

    /** @var list<array<string, mixed>> $rooms */
    /** @var list<array<string, mixed>> $agents */
    /** @var array<string, mixed> $simulation */
    $developmentRoom = officeProjectionBuilderRow(
        $rooms,
        'development_floor',
    );

    $agent = collect($agents)
        ->firstWhere('id', $execution->id);

    if (! is_array($agent)) {
        throw new RuntimeException(
            'The expected development agent was not projected.',
        );
    }

    expect($first->schema_version)->toBe(1)
        ->and($first->id)->toBe($second->id)
        ->and($first->fingerprint)->toBe($second->fingerprint)
        ->and(OfficeProjection::query()->count())->toBe(1)
        ->and($developmentRoom['state'])->toBe('working')
        ->and($developmentRoom['activeAgents'])->toBe(1)
        ->and($agent['room'])->toBe('development_floor')
        ->and($agent['officeState'])->toBe('implementing')
        ->and($simulation['labelRequired'])->toBeTrue()
        ->and($simulation['actualState'])
        ->toBe('unverified');
});

it('keeps the newest durable checkpoint when events are replayed out of order', function (): void {
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-118-CHECKPOINT',
    );

    approveOfficeProjectionBuilderRoadmap($fixture['roadmap']);

    $project = $fixture['project'];
    $correlationId = (string) Str::ulid();

    $older = OutboxMessage::query()->create([
        'event_id' => (string) Str::ulid(),
        'event_name' => AuditEventType::TicketSelected->value,
        'aggregate_type' => 'roadmap_task',
        'aggregate_id' => (string) $fixture['ticket']->id,
        'organization_id' => $project->organization_id,
        'project_id' => $project->id,
        'occurred_at' => now()->subSecond(),
        'correlation_id' => $correlationId,
        'causation_id' => null,
        'execution_id' => null,
        'schema_version' => 1,
        'envelope' => [
            'payload' => [
                'ticket_id' => $fixture['ticket']->stable_id,
            ],
        ],
        'published_at' => now(),
        'created_at' => now()->subSecond(),
    ]);

    $newer = OutboxMessage::query()->create([
        'event_id' => (string) Str::ulid(),
        'event_name' => AuditEventType::TicketStatusTransitioned->value,
        'aggregate_type' => 'roadmap_task',
        'aggregate_id' => (string) $fixture['ticket']->id,
        'organization_id' => $project->organization_id,
        'project_id' => $project->id,
        'occurred_at' => now(),
        'correlation_id' => $correlationId,
        'causation_id' => $older->event_id,
        'execution_id' => null,
        'schema_version' => 1,
        'envelope' => [
            'payload' => [
                'ticket_id' => $fixture['ticket']->stable_id,
            ],
        ],
        'published_at' => now(),
        'created_at' => now(),
    ]);

    $consumer = app(RefreshOfficeProjection::class);

    foreach ([$newer, $older] as $message) {
        $consumer->handle(
            new StoredDomainEvent(
                eventId: $message->event_id,
                eventName: $message->event_name,
                organizationId: $message->organization_id,
                projectId: $message->project_id,
                schemaVersion: $message->schema_version,
                envelope: $message->envelope,
            ),
        );
    }

    $projection = OfficeProjection::query()
        ->forOrganization($project->organization_id)
        ->forProject($project->id)
        ->firstOrFail();

    expect($projection->last_event_sequence)->toBe($newer->sequence)
        ->and($projection->last_event_id)->toBe($newer->event_id)
        ->and(OfficeProjection::query()->count())->toBe(1);
});

it('returns the office projection endpoint to authorized project members', function (): void {
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-118-ENDPOINT',
    );

    approveOfficeProjectionBuilderRoadmap($fixture['roadmap']);

    $project = $fixture['project'];
    $user = CompletedQualityAssuranceFixture::user(
        $project->organization_id,
    );

    $this
        ->actingAs($user)
        ->getJson(route(
            'organizations.projects.operations.office-projection.show',
            [
                'organization' => $project->organization,
                'project' => $project,
            ],
        ))
        ->assertOk()
        ->assertJsonPath('metadata.schemaVersion', 1)
        ->assertJsonPath('project.id', $project->id)
        ->assertJsonStructure([
            'metadata' => [
                'schemaVersion',
                'fingerprint',
                'lastEventSequence',
                'lastEventId',
                'projectedAt',
                'rebuiltAt',
            ],
            'project',
            'workflow',
            'roadmap',
            'summary',
            'rooms',
            'agents',
            'indicators',
            'simulation',
        ]);

    $this->assertDatabaseHas('office_projections', [
        'organization_id' => $project->organization_id,
        'project_id' => $project->id,
    ]);
});

it('conceals an office projection from another organization', function (): void {
    $currentFixture = TicketTestFixture::create(
        stableId: 'AIOS-118-CURRENT',
    );

    $otherFixture = TicketTestFixture::create(
        stableId: 'AIOS-118-OTHER',
    );

    $user = CompletedQualityAssuranceFixture::user(
        $currentFixture['project']->organization_id,
    );

    $this
        ->actingAs($user)
        ->getJson(route(
            'organizations.projects.operations.office-projection.show',
            [
                'organization' => $otherFixture['project']->organization,
                'project' => $otherFixture['project'],
            ],
        ))
        ->assertNotFound();
});
