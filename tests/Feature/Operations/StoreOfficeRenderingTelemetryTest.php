<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

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

    $logger = Mockery::mock(LoggerInterface::class);

    $logger
        ->shouldReceive('info')
        ->once()
        ->with(
            'office.renderer.telemetry',
            Mockery::on(
                fn (array $context): bool => $context['organization_id'] === $organization->id
                    && $context['project_id'] === $project->id
                    && $context['actor_id'] === $user->id
                    && $context['renderer']['type'] === 'frame_window',
            ),
        );

    Log::shouldReceive('channel')
        ->once()
        ->with('json')
        ->andReturn($logger);

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
});

it('rejects sensitive content and device fingerprint fields', function (): void {
    [$user, $organization, $project] =
        createTelemetryProjectContext();

    $event = validRendererEvent();
    $event['ticketId'] = 'AIOS-135';
    $event['gpuRenderer'] = 'Sensitive device fingerprint';

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
                'events' => [$event],
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'events.0.ticketId',
            'events.0.gpuRenderer',
        ]);
});

it('does not resolve telemetry through another organization', function (): void {
    [$user, $organization] = createTelemetryProjectContext();

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
                'events' => [validRendererEvent()],
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
                'events' => [validRendererEvent()],
            ],
        )
        ->assertUnauthorized();
});
