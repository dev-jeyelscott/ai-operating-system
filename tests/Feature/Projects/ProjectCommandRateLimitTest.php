<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    /*
     * Clear the exact cache store used by Laravel's HTTP rate limiter.
     *
     * PHPUnit configures this as the process-local array store, preventing
     * counters from leaking between tests or persisting in Redis.
     */
    Cache::store((string) config('cache.limiter'))->flush();

    /*
     * Use low limits so each test can exercise throttling without issuing
     * an excessive number of application requests.
     */
    config()->set(
        'rate-limits.project_commands.store.per_minute',
        2,
    );

    config()->set(
        'rate-limits.project_commands.store.per_hour',
        20,
    );

    config()->set(
        'rate-limits.project_commands.update.per_minute',
        2,
    );

    config()->set(
        'rate-limits.project_commands.update.per_hour',
        20,
    );

    config()->set(
        'rate-limits.project_commands.archive.per_minute',
        2,
    );

    config()->set(
        'rate-limits.project_commands.archive.per_hour',
        20,
    );

    config()->set(
        'rate-limits.project_commands.restore.per_minute',
        2,
    );

    config()->set(
        'rate-limits.project_commands.restore.per_hour',
        20,
    );
});

/**
 * Create an owner membership for a specific user and organization.
 */
function createRateLimitOwnerMembership(
    User $user,
    Organization $organization,
): void {
    OrganizationMembership::factory()
        ->owner()
        ->for($organization)
        ->for($user)
        ->create();
}

/**
 * Create an organization and a user who owns that organization.
 *
 * @return array{
 *     user: User,
 *     organization: Organization
 * }
 */
function createRateLimitOrganizationOwner(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    createRateLimitOwnerMembership(
        user: $user,
        organization: $organization,
    );

    return [
        'user' => $user,
        'organization' => $organization,
    ];
}

test('project creation is rate limited per actor and organization', function () {
    ['user' => $user, 'organization' => $organization]
        = createRateLimitOrganizationOwner();

    /*
     * Consume the two project-creation attempts allowed by the test-specific
     * rate-limit configuration.
     */
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $this
            ->actingAs($user)
            ->post(
                route('organizations.projects.store', [
                    'organization' => $organization,
                ]),
                [
                    'name' => "Rate Limited Project {$attempt}",
                    'description' => null,
                    'project_type' => ProjectType::WebApplication->value,
                ],
            )
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /*
     * The third project-creation command for the same actor and organization
     * must be rejected before persistent state is changed.
     */
    $response = $this
        ->actingAs($user)
        ->from(
            route('organizations.projects.create', [
                'organization' => $organization,
            ]),
        )
        ->post(
            route('organizations.projects.store', [
                'organization' => $organization,
            ]),
            [
                'name' => 'Blocked Project',
                'description' => null,
                'project_type' => ProjectType::WebApplication->value,
            ],
        );

    $response
        ->assertRedirect(
            route('organizations.projects.create', [
                'organization' => $organization,
            ]),
        )
        ->assertHeader('Retry-After')
        ->assertSessionHasErrors('rate_limit');

    expect(
        Project::query()
            ->where('organization_id', $organization->id)
            ->where('name', 'Blocked Project')
            ->exists(),
    )->toBeFalse();
});

test('project command json responses use the retryable error contract', function () {
    ['user' => $user, 'organization' => $organization]
        = createRateLimitOrganizationOwner();

    /*
     * Consume the permitted project-creation attempts through regular browser
     * requests before testing the explicit JSON response contract.
     */
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $this
            ->actingAs($user)
            ->post(
                route('organizations.projects.store', [
                    'organization' => $organization,
                ]),
                [
                    'name' => "JSON Rate Limited Project {$attempt}",
                    'description' => null,
                    'project_type' => ProjectType::WebApplication->value,
                ],
            )
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /*
     * JSON clients must receive the platform's stable retryable error envelope
     * rather than the redirect-based browser response.
     */
    $response = $this
        ->actingAs($user)
        ->withHeader('X-Request-ID', 'project-command-request')
        ->postJson(
            route('organizations.projects.store', [
                'organization' => $organization,
            ]),
            [
                'name' => 'JSON Blocked Project',
                'description' => null,
                'project_type' => ProjectType::WebApplication->value,
            ],
        );

    $response
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJson([
            'error' => [
                'code' => 'rate_limit_exceeded',
                'message' => 'Too many requests. Please retry later.',
                'retryable' => true,
                'request_id' => 'project-command-request',
            ],
        ]);

    expect(
        $response->json('error.details.retry_after_seconds'),
    )->toBeInt()->toBeGreaterThan(0);

    expect(
        Project::query()
            ->where('organization_id', $organization->id)
            ->where('name', 'JSON Blocked Project')
            ->exists(),
    )->toBeFalse();
});

test('project command limits are isolated between organizations', function () {
    ['user' => $user, 'organization' => $firstOrganization]
        = createRateLimitOrganizationOwner();

    $secondOrganization = Organization::factory()->create();

    createRateLimitOwnerMembership(
        user: $user,
        organization: $secondOrganization,
    );

    /*
     * Exhaust the user's project-creation limit inside only the first
     * organization.
     */
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $this
            ->actingAs($user)
            ->post(
                route('organizations.projects.store', [
                    'organization' => $firstOrganization,
                ]),
                [
                    'name' => "First Organization {$attempt}",
                    'description' => null,
                    'project_type' => ProjectType::WebApplication->value,
                ],
            )
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /*
     * The same actor must retain an independent limiter bucket in a different
     * organization.
     */
    $this
        ->actingAs($user)
        ->post(
            route('organizations.projects.store', [
                'organization' => $secondOrganization,
            ]),
            [
                'name' => 'Second Organization Project',
                'description' => null,
                'project_type' => ProjectType::WebApplication->value,
            ],
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(
        Project::query()
            ->where('organization_id', $secondOrganization->id)
            ->where('name', 'Second Organization Project')
            ->exists(),
    )->toBeTrue();
});

test('project command limits are isolated between authenticated actors', function () {
    ['user' => $firstUser, 'organization' => $organization]
        = createRateLimitOrganizationOwner();

    $secondUser = User::factory()->create();

    createRateLimitOwnerMembership(
        user: $secondUser,
        organization: $organization,
    );

    /*
     * Exhaust only the first actor's project-creation limit.
     */
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $this
            ->actingAs($firstUser)
            ->post(
                route('organizations.projects.store', [
                    'organization' => $organization,
                ]),
                [
                    'name' => "First User {$attempt}",
                    'description' => null,
                    'project_type' => ProjectType::WebApplication->value,
                ],
            )
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /*
     * A different authenticated actor in the same organization must retain an
     * independent limiter bucket.
     */
    $this
        ->actingAs($secondUser)
        ->post(
            route('organizations.projects.store', [
                'organization' => $organization,
            ]),
            [
                'name' => 'Second User Project',
                'description' => null,
                'project_type' => ProjectType::WebApplication->value,
            ],
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(
        Project::query()
            ->where('organization_id', $organization->id)
            ->where('name', 'Second User Project')
            ->exists(),
    )->toBeTrue();
});

test('privileged project mutation commands are rate limited', function (
    string $routeName,
    string $method,
    string $command,
) {
    ['user' => $user, 'organization' => $organization]
        = createRateLimitOrganizationOwner();

    $project = Project::factory()
        ->for($organization)
        ->create();

    /*
     * Allow one successful command so the next matching command is rejected.
     */
    config()->set(
        "rate-limits.project_commands.{$command}.per_minute",
        1,
    );

    $parameters = [
        'organization' => $organization,
        'project' => $project,
    ];

    /*
     * Only the update command requires a request payload. Archive and restore
     * commands operate entirely on the route-bound project.
     */
    $payload = match ($command) {
        'update' => [
            'name' => $project->name,
            'description' => $project->description,
            'project_type' => $project->project_type->value,
            'status' => $project->status->value,
        ],

        default => [],
    };

    /*
     * The first command is permitted.
     */
    $this
        ->actingAs($user)
        ->call(
            $method,
            route($routeName, $parameters),
            $payload,
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    /*
     * The second command with the same actor, organization, and command name
     * must be rejected through the browser response contract.
     */
    $this
        ->actingAs($user)
        ->from(route('organizations.projects.show', $parameters))
        ->call(
            $method,
            route($routeName, $parameters),
            $payload,
        )
        ->assertRedirect(
            route('organizations.projects.show', $parameters),
        )
        ->assertHeader('Retry-After')
        ->assertSessionHasErrors('rate_limit');
})->with([
    'update' => [
        'organizations.projects.update',
        'PUT',
        'update',
    ],
    'archive' => [
        'organizations.projects.archive',
        'PUT',
        'archive',
    ],
    'restore' => [
        'organizations.projects.restore',
        'PUT',
        'restore',
    ],
]);
