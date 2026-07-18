<?php

declare(strict_types=1);

use App\Domain\Identity\OrganizationRole;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ProjectType;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Attach an explicit organization membership to a test user.
 */
function attachOrganizationIsolationMembership(
    User $user,
    Organization $organization,
    OrganizationRole $role = OrganizationRole::Owner,
): void {
    OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);
}

/**
 * Return valid request data for project command routes.
 *
 * Supplying valid data ensures authorization and tenant isolation are what
 * reject the request rather than ordinary form validation.
 *
 * @return array<string, string|null>
 */
function organizationIsolationRequestPayload(string $routeName): array
{
    return match ($routeName) {
        'organizations.projects.store' => [
            'name' => 'Injected Foreign Project',
            'description' => 'This project must never be created.',
            'project_type' => ProjectType::WebApplication->value,
        ],

        'organizations.projects.update' => [
            'name' => 'Injected Foreign Update',
            'description' => 'This update must never be persisted.',
            'project_type' => ProjectType::Api->value,
        ],

        default => [],
    };
}

/**
 * Capture the project properties that cross-tenant requests must not change.
 *
 * @return array{
 *     organization_id: int,
 *     name: string,
 *     slug: string,
 *     description: string|null,
 *     project_type: string,
 *     status: string,
 *     archived_at: string|null
 * }
 */
function organizationIsolationProjectSnapshot(Project $project): array
{
    $project->refresh();

    return [
        'organization_id' => $project->organization_id,
        'name' => $project->name,
        'slug' => $project->slug,
        'description' => $project->description,
        'project_type' => $project->project_type->value,
        'status' => $project->status->value,
        'archived_at' => $project->archived_at?->toIso8601String(),
    ];
}

/*
|--------------------------------------------------------------------------
| Isolation datasets
|--------------------------------------------------------------------------
|
| These datasets exercise every current organization- and project-scoped
| delivery endpoint. Each dataset entry becomes an independently reported
| Pest test case.
|
*/

dataset('foreign organization endpoints', [
    'organization dashboard' => [
        'GET',
        'organizations.dashboard',
    ],
    'organization switch command' => [
        'PUT',
        'organizations.current.update',
    ],
    'project index' => [
        'GET',
        'organizations.projects.index',
    ],
    'project creation page' => [
        'GET',
        'organizations.projects.create',
    ],
    'project store command' => [
        'POST',
        'organizations.projects.store',
    ],
]);

dataset('foreign project endpoints', [
    'project details' => [
        'GET',
        'organizations.projects.show',
        false,
    ],
    'project edit page' => [
        'GET',
        'organizations.projects.edit',
        false,
    ],
    'project update command' => [
        'PUT',
        'organizations.projects.update',
        false,
    ],
    'project archive command' => [
        'PUT',
        'organizations.projects.archive',
        false,
    ],
    'project restore command' => [
        'PUT',
        'organizations.projects.restore',
        true,
    ],
]);

test(
    'a non-member receives not found from :dataset',
    function (
        string $method,
        string $routeName,
    ): void {
        $actor = User::factory()->create();

        $currentOrganization = Organization::factory()->create();

        attachOrganizationIsolationMembership(
            user: $actor,
            organization: $currentOrganization,
        );

        $foreignOrganization = Organization::factory()->create();

        Project::factory()
            ->for($foreignOrganization)
            ->create([
                'name' => 'Existing Foreign Project',
            ]);

        $projectCountBeforeRequest = Project::query()->count();
        $auditCountBeforeRequest = AuditEvent::query()->count();

        $response = $this
            ->actingAs($actor)
            ->withSession([
                'current_organization_id' => $currentOrganization->id,
            ])
            ->call(
                $method,
                route($routeName, [
                    'organization' => $foreignOrganization,
                ]),
                organizationIsolationRequestPayload($routeName),
            );

        $response
            ->assertNotFound()
            ->assertSessionHas(
                'current_organization_id',
                $currentOrganization->id,
            );

        expect(Project::query()->count())
            ->toBe($projectCountBeforeRequest)
            ->and(AuditEvent::query()->count())
            ->toBe($auditCountBeforeRequest);
    },
)->with('foreign organization endpoints');

test(
    'a non-member cannot access or mutate a foreign project through :dataset',
    function (
        string $method,
        string $routeName,
        bool $startsArchived,
    ): void {
        $actor = User::factory()->create();

        $currentOrganization = Organization::factory()->create();

        attachOrganizationIsolationMembership(
            user: $actor,
            organization: $currentOrganization,
        );

        $foreignOrganization = Organization::factory()->create();

        $foreignProject = Project::factory()
            ->for($foreignOrganization)
            ->create([
                'name' => 'Protected Foreign Project',
                'description' => 'Original description.',
                'project_type' => ProjectType::WebApplication,
                'status' => ProjectStatus::Draft,
                'archived_at' => $startsArchived ? now() : null,
            ]);

        $snapshotBeforeRequest = organizationIsolationProjectSnapshot(
            $foreignProject,
        );

        $auditCountBeforeRequest = AuditEvent::query()->count();

        $response = $this
            ->actingAs($actor)
            ->withSession([
                'current_organization_id' => $currentOrganization->id,
            ])
            ->call(
                $method,
                route($routeName, [
                    'organization' => $foreignOrganization,
                    'project' => $foreignProject,
                ]),
                organizationIsolationRequestPayload($routeName),
            );

        $response
            ->assertNotFound()
            ->assertSessionHas(
                'current_organization_id',
                $currentOrganization->id,
            );

        expect(
            organizationIsolationProjectSnapshot($foreignProject),
        )
            ->toBe($snapshotBeforeRequest)
            ->and(AuditEvent::query()->count())
            ->toBe($auditCountBeforeRequest);
    },
)->with('foreign project endpoints');

test(
    'scoped binding rejects parent-child project substitution through :dataset',
    function (
        string $method,
        string $routeName,
        bool $startsArchived,
    ): void {
        $actor = User::factory()->create();

        /*
         * The actor deliberately belongs to both organizations. Therefore, a
         * 404 response cannot be attributed merely to missing membership. It
         * must come from the parent-child scoped route binding.
         */
        $routeOrganization = Organization::factory()->create();
        $owningOrganization = Organization::factory()->create();

        attachOrganizationIsolationMembership(
            user: $actor,
            organization: $routeOrganization,
        );

        attachOrganizationIsolationMembership(
            user: $actor,
            organization: $owningOrganization,
        );

        $foreignProject = Project::factory()
            ->for($owningOrganization)
            ->create([
                'name' => 'Wrong Parent Project',
                'description' => 'Original description.',
                'project_type' => ProjectType::WebApplication,
                'status' => ProjectStatus::Draft,
                'archived_at' => $startsArchived ? now() : null,
            ]);

        $snapshotBeforeRequest = organizationIsolationProjectSnapshot(
            $foreignProject,
        );

        $auditCountBeforeRequest = AuditEvent::query()->count();

        $this
            ->actingAs($actor)
            ->call(
                $method,
                route($routeName, [
                    'organization' => $routeOrganization,
                    'project' => $foreignProject,
                ]),
                organizationIsolationRequestPayload($routeName),
            )
            ->assertNotFound();

        expect(
            organizationIsolationProjectSnapshot($foreignProject),
        )
            ->toBe($snapshotBeforeRequest)
            ->and(AuditEvent::query()->count())
            ->toBe($auditCountBeforeRequest);
    },
)->with('foreign project endpoints');

test(
    'project listings never expose another organizations projects',
    function (): void {
        $actor = User::factory()->create();

        $visibleOrganization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();

        attachOrganizationIsolationMembership(
            user: $actor,
            organization: $visibleOrganization,
        );

        $visibleProject = Project::factory()
            ->for($visibleOrganization)
            ->create([
                'name' => 'Visible Project',
            ]);

        $foreignProject = Project::factory()
            ->for($foreignOrganization)
            ->create([
                'name' => 'Foreign Secret Project',
            ]);

        $this
            ->actingAs($actor)
            ->get(
                route('organizations.projects.index', [
                    'organization' => $visibleOrganization,
                ]),
            )
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('projects/index')
                    ->has('projects.data', 1)
                    ->where(
                        'projects.data.0.id',
                        $visibleProject->id,
                    )
                    ->where(
                        'projects.data.0.name',
                        'Visible Project',
                    )
                    ->missing('projects.data.1'),
            );

        expect($foreignProject->organization_id)
            ->toBe($foreignOrganization->id);
    },
);

test(
    'forged session organization does not grant access and is repaired',
    function (): void {
        $actor = User::factory()->create();

        $authorizedOrganization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();

        attachOrganizationIsolationMembership(
            user: $actor,
            organization: $authorizedOrganization,
        );

        /*
         * Confirm the fixture represents a real tenant-isolation boundary.
         * The actor must not have membership in the foreign organization.
         */
        expect(
            OrganizationMembership::query()
                ->where('user_id', $actor->id)
                ->where(
                    'organization_id',
                    $foreignOrganization->id,
                )
                ->exists(),
        )->toBeFalse();

        /*
         * Simulate a stale or tampered organization preference.
         *
         * Session state is only a navigation preference and must never grant
         * access. The request must return 404 and repair the session by falling
         * back to the actor's first authorized organization.
         */
        $this
            ->actingAs($actor)
            ->withSession([
                'current_organization_id' => $foreignOrganization->id,
            ])
            ->get(
                route('organizations.dashboard', [
                    'organization' => $foreignOrganization,
                ]),
            )
            ->assertNotFound()
            ->assertSessionHas(
                'current_organization_id',
                $authorizedOrganization->id,
            );
    },
);

test(
    'json clients cannot distinguish policy denial from scoped binding failure',
    function (): void {
        $actor = User::factory()->create();

        $firstOrganization = Organization::factory()->create();
        $secondOrganization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();

        attachOrganizationIsolationMembership(
            user: $actor,
            organization: $firstOrganization,
        );

        attachOrganizationIsolationMembership(
            user: $actor,
            organization: $secondOrganization,
        );

        $secondProject = Project::factory()
            ->for($secondOrganization)
            ->create();

        $foreignProject = Project::factory()
            ->for($foreignOrganization)
            ->create();

        /*
         * This request resolves the project but fails its project policy because
         * the actor has no membership in the project's organization.
         */
        $policyDenial = $this
            ->actingAs($actor)
            ->withHeader(
                'X-Request-ID',
                'isolation-policy-denial',
            )
            ->getJson(
                route('organizations.projects.show', [
                    'organization' => $foreignOrganization,
                    'project' => $foreignProject,
                ]),
            );

        /*
         * This request fails scoped model binding because the project does not
         * belong to the organization present in the URL.
         */
        $bindingFailure = $this
            ->actingAs($actor)
            ->withHeader(
                'X-Request-ID',
                'isolation-binding-failure',
            )
            ->getJson(
                route('organizations.projects.show', [
                    'organization' => $firstOrganization,
                    'project' => $secondProject,
                ]),
            );

        $policyDenial
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'resource_not_found',
            )
            ->assertJsonPath(
                'error.message',
                'The requested resource was not found.',
            )
            ->assertJsonPath(
                'error.retryable',
                false,
            )
            ->assertJsonPath(
                'error.request_id',
                'isolation-policy-denial',
            );

        $bindingFailure
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'resource_not_found',
            )
            ->assertJsonPath(
                'error.message',
                'The requested resource was not found.',
            )
            ->assertJsonPath(
                'error.retryable',
                false,
            )
            ->assertJsonPath(
                'error.request_id',
                'isolation-binding-failure',
            );
    },
);
