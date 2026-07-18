<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear('unused');

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
 * Create an organization owner with an authenticated organization context.
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

    /*
     * Adjust the membership factory/relationship call below only if the
     * repository uses a differently named owner-membership helper.
     */
    $organization->members()->attach($user, [
        'role' => 'owner',
    ]);

    return [
        'user' => $user,
        'organization' => $organization,
    ];
}

test('project creation is rate limited per actor and organization', function () {
    ['user' => $user, 'organization' => $organization]
        = createRateLimitOrganizationOwner();

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
                    'project_type' => 'software',
                ],
            )
            ->assertRedirect();
    }

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
                'project_type' => 'software',
            ],
        );

    $response
        ->assertRedirect()
        ->assertSessionHasErrors('rate_limit');

    expect(
        Project::query()
            ->where('name', 'Blocked Project')
            ->exists(),
    )->toBeFalse();
});

test('project command json responses use the retryable error contract', function () {
    ['user' => $user, 'organization' => $organization]
        = createRateLimitOrganizationOwner();

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
                    'project_type' => 'software',
                ],
            )
            ->assertRedirect();
    }

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
                'project_type' => 'software',
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
});

test('project command limits are isolated between organizations', function () {
    ['user' => $user, 'organization' => $firstOrganization]
        = createRateLimitOrganizationOwner();

    $secondOrganization = Organization::factory()->create();

    $secondOrganization->members()->attach($user, [
        'role' => 'owner',
    ]);

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
                    'project_type' => 'software',
                ],
            )
            ->assertRedirect();
    }

    $this
        ->actingAs($user)
        ->post(
            route('organizations.projects.store', [
                'organization' => $secondOrganization,
            ]),
            [
                'name' => 'Second Organization Project',
                'description' => null,
                'project_type' => 'software',
            ],
        )
        ->assertRedirect();

    expect(
        Project::query()
            ->where('name', 'Second Organization Project')
            ->exists(),
    )->toBeTrue();
});

test('project command limits are isolated between authenticated actors', function () {
    ['user' => $firstUser, 'organization' => $organization]
        = createRateLimitOrganizationOwner();

    $secondUser = User::factory()->create();

    $organization->members()->attach($secondUser, [
        'role' => 'owner',
    ]);

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
                    'project_type' => 'software',
                ],
            )
            ->assertRedirect();
    }

    $this
        ->actingAs($secondUser)
        ->post(
            route('organizations.projects.store', [
                'organization' => $organization,
            ]),
            [
                'name' => 'Second User Project',
                'description' => null,
                'project_type' => 'software',
            ],
        )
        ->assertRedirect();

    expect(
        Project::query()
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

    config()->set(
        "rate-limits.project_commands.{$command}.per_minute",
        1,
    );

    $parameters = [
        'organization' => $organization,
        'project' => $project,
    ];

    $payload = match ($command) {
        'update' => [
            'name' => $project->name,
            'description' => $project->description,
            'project_type' => $project->project_type->value,
            'status' => $project->status->value,
        ],

        default => [],
    };

    $this
        ->actingAs($user)
        ->call(
            $method,
            route($routeName, $parameters),
            $payload,
        )
        ->assertRedirect();

    $this
        ->actingAs($user)
        ->from(route('organizations.projects.show', $parameters))
        ->call(
            $method,
            route($routeName, $parameters),
            $payload,
        )
        ->assertRedirect()
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
