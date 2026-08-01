<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Create one tenant owner and project for telemetry tests.
 *
 * @return array{0: User, 1: Organization, 2: Project}
 */
function createTelemetryProjectContext(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()
        ->owner()
        ->for($organization)
        ->for($user)
        ->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    return [$user, $organization, $project];
}

/**
 * Return one valid renderer event.
 *
 * @return array<string, mixed>
 */
function validRendererEvent(): array
{
    return [
        'type' => 'frame_window',
        'sessionId' => '00000000-0000-4000-8000-000000000001',
        'sequence' => 1,
        'observedAt' => '2026-08-01T05:30:00.000Z',
        'qualityPreset' => 'balanced',
        'reducedMotion' => false,
        'capabilityStatus' => 'supported',
        'capabilityReason' => 'webgl2_available',
        'frame' => [
            'averageFps' => 58.8,
            'p95FrameMs' => 18.4,
            'maxFrameMs' => 24.1,
            'sampleDurationMs' => 5001,
            'frameCount' => 294,
            'drawCalls' => 24,
            'triangles' => 18200,
            'geometries' => 18,
            'textures' => 4,
            'degraded' => false,
        ],
    ];
}

it('records a bounded privacy-safe renderer batch', function (): void {
    [$user, $organization, $project] =
        createTelemetryProjectContext();

    /**
     * Capture Laravel's real MessageLogged event without replacing the global
     * LogManager or interfering with exception reporting.
     *
     * @var list<MessageLogged> $records
     */
    $records = [];

    Event::listen(
        MessageLogged::class,
        function (MessageLogged $event) use (&$records): void {
            if ($event->message !== 'office.renderer.telemetry') {
                return;
            }

            $records[] = $event;
        },
    );

    $this
        ->actingAs($user)
        ->postJson(
            route(
                'organizations.projects.operations.office-telemetry.store',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            [
                'events' => [
                    validRendererEvent(),
                ],
            ],
        )
        ->assertNoContent(202);

    expect($records)->toHaveCount(1);

    $record = $records[0] ?? null;

    expect($record)->toBeInstanceOf(MessageLogged::class);

    if (! $record instanceof MessageLogged) {
        throw new RuntimeException(
            'The expected renderer telemetry log record was not emitted.',
        );
    }

    $renderer = $record->context['renderer'] ?? null;

    expect($renderer)->toBeArray();

    if (! is_array($renderer)) {
        throw new RuntimeException(
            'The renderer telemetry context must be an array.',
        );
    }

    expect($record->level)->toBe('info')
        ->and($record->message)->toBe('office.renderer.telemetry')
        ->and($record->context['organization_id'] ?? null)
        ->toBe($organization->id)
        ->and($record->context['project_id'] ?? null)
        ->toBe($project->id)
        ->and($record->context['actor_id'] ?? null)
        ->toBe($user->id)
        ->and($renderer['type'] ?? null)
        ->toBe('frame_window')
        ->and($renderer['qualityPreset'] ?? null)
        ->toBe('balanced');

    /*
     * Confirm that content identifiers and device-fingerprint properties never
     * reach the structured telemetry record.
     */
    expect(array_keys($renderer))
        ->not->toContain(
            'projectName',
            'projectSlug',
            'ticketId',
            'agentId',
            'provider',
            'currentAction',
            'url',
            'userAgent',
            'gpuVendor',
            'gpuRenderer',
            'message',
        );
});

it('rejects unknown content and device fingerprint fields', function (): void {
    [$user, $organization, $project] =
        createTelemetryProjectContext();

    $event = validRendererEvent();
    $event['ticketId'] = 'AIOS-135';
    $event['gpuRenderer'] = 'Sensitive device fingerprint';

    $response = $this
        ->actingAs($user)
        ->postJson(
            route(
                'organizations.projects.operations.office-telemetry.store',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            [
                'events' => [$event],
            ],
        );

    $response
        ->assertUnprocessable()
        ->assertJsonPath(
            'error.code',
            'validation_failed',
        )
        ->assertJsonPath(
            'error.message',
            'The submitted data is invalid.',
        );

    /*
     * The project uses a canonical API envelope:
     *
     * error.details.fields
     *
     * The allowed-key array rule attaches this failure to the event array
     * itself rather than creating one error for every unknown property.
     */
    $fields = $response->json('error.details.fields');

    expect($fields)->toBeArray();

    if (! is_array($fields)) {
        throw new RuntimeException(
            'The validation response did not contain a fields array.',
        );
    }

    expect($fields)
        ->toHaveKey('events.0');

    expect($fields['events.0'])
        ->toBeArray()
        ->not->toBeEmpty();
});

it('rejects unknown nested frame fields', function (): void {
    [$user, $organization, $project] =
        createTelemetryProjectContext();

    $event = validRendererEvent();
    $event['frame']['gpuTemperature'] = 82;

    $response = $this
        ->actingAs($user)
        ->postJson(
            route(
                'organizations.projects.operations.office-telemetry.store',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            [
                'events' => [$event],
            ],
        );

    $response
        ->assertUnprocessable()
        ->assertJsonPath(
            'error.code',
            'validation_failed',
        )
        ->assertJsonPath(
            'error.message',
            'The submitted data is invalid.',
        );

    /*
     * The nested frame allowlist attaches unknown-key failures to the frame
     * array attribute.
     */
    $fields = $response->json('error.details.fields');

    expect($fields)->toBeArray();

    if (! is_array($fields)) {
        throw new RuntimeException(
            'The validation response did not contain a fields array.',
        );
    }

    expect($fields)
        ->toHaveKey('events.0.frame');

    expect($fields['events.0.frame'])
        ->toBeArray()
        ->not->toBeEmpty();
});

it('does not resolve telemetry through another organization', function (): void {
    [$user, $organization] =
        createTelemetryProjectContext();

    $otherOrganization = Organization::factory()->create();

    $otherProject = Project::factory()
        ->for($otherOrganization)
        ->create();

    $this
        ->actingAs($user)
        ->postJson(
            route(
                'organizations.projects.operations.office-telemetry.store',
                [
                    'organization' => $organization,
                    'project' => $otherProject,
                ],
            ),
            [
                'events' => [
                    validRendererEvent(),
                ],
            ],
        )
        ->assertNotFound();
});

it('requires authentication', function (): void {
    [, $organization, $project] =
        createTelemetryProjectContext();

    $this
        ->postJson(
            route(
                'organizations.projects.operations.office-telemetry.store',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            [
                'events' => [
                    validRendererEvent(),
                ],
            ],
        )
        ->assertUnauthorized();
});
