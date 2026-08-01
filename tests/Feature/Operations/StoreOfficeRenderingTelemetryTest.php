<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;

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

    /*
     * Capture log records without placing strict expectations on every method
     * of Laravel's global LogManager.
     */
    $logger = new class extends AbstractLogger
    {
        /**
         * Store emitted records for assertions.
         *
         * @var list<array{
         *     level: string,
         *     message: string,
         *     context: array<string, mixed>
         * }>
         */
        public array $records = [];

        /**
         * Capture one PSR log record in memory.
         *
         * @param  mixed  $level
         * @param  array<string, mixed>  $context
         */
        public function log(
            $level,
            Stringable|string $message,
            array $context = [],
        ): void {
            $this->records[] = [
                'level' => (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    };

    /*
     * Use a partial facade mock so unrelated logging methods, including error
     * reporting from Laravel's exception handler, are not blocked by Mockery.
     */
    Log::partialMock()
        ->shouldReceive('channel')
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

    expect($logger->records)->toHaveCount(1);

    $record = $logger->records[0];

    expect($record['level'])->toBe('info')
        ->and($record['message'])->toBe('office.renderer.telemetry')
        ->and($record['context']['organization_id'])
        ->toBe($organization->id)
        ->and($record['context']['project_id'])
        ->toBe($project->id)
        ->and($record['context']['actor_id'])
        ->toBe($user->id)
        ->and($record['context']['renderer']['type'])
        ->toBe('frame_window')
        ->and($record['context']['renderer']['qualityPreset'])
        ->toBe('balanced');

    expect(array_keys($record['context']['renderer']))
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
            /*
             * The event array's allowed-key rule owns the validation failure.
             */
            'events.0',
        ]);
});

it('rejects unknown nested frame fields', function (): void {
    [$user, $organization, $project] =
        createTelemetryProjectContext();

    $event = validRendererEvent();
    $event['frame']['gpuTemperature'] = 82;

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
            /*
             * The nested frame array's allowed-key rule owns the failure.
             */
            'events.0.frame',
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
