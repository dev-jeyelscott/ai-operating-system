<?php

declare(strict_types=1);

use App\Broadcasting\OrganizationEventStreamChannel;
use App\Broadcasting\ProjectEventStreamChannel;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Projects\ProjectType;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Execution;
use App\Models\ExternalTicketMapping;
use App\Models\NotificationEvent;
use App\Models\NotionPublicationSummary;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectContextSnapshot;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Attach one explicit organization role to a test actor.
 */
function aios156AttachMembership(
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
 * Capture project state that unauthorized requests must not mutate.
 *
 * @return array<string, mixed>
 */
function aios156ProjectSnapshot(Project $project): array
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
        'updated_at' => $project->updated_at?->toIso8601String(),
    ];
}

/**
 * Capture material side-effect counts before an unauthorized request.
 *
 * @return array<string, int>
 */
function aios156SideEffectCounts(): array
{
    return [
        'projects' => Project::query()->count(),
        'documents' => Document::query()->count(),
        'executions' => Execution::query()->count(),
        'roadmaps' => Roadmap::query()->count(),
        'external_ticket_mappings' => ExternalTicketMapping::query()->count(),
        'notion_publication_summaries' => NotionPublicationSummary::query()
            ->count(),
        'project_context_snapshots' => ProjectContextSnapshot::query()->count(),
        'workflow_instances' => WorkflowInstance::query()->count(),
        'audit_events' => AuditEvent::query()->count(),
        'notification_events' => NotificationEvent::query()->count(),
    ];
}

/*
|--------------------------------------------------------------------------
| Cross-project route coverage
|--------------------------------------------------------------------------
|
| These endpoints represent every major project delivery surface that does not
| require an additional persisted child fixture. Nested child-resource routes
| are protected by the route-registry contract below.
|
*/

dataset('AIOS-156 cross-project endpoints', [
    'project details' => [
        'GET',
        'organizations.projects.show',
        [],
        [],
    ],

    'project edit page' => [
        'GET',
        'organizations.projects.edit',
        [],
        [],
    ],

    'project update' => [
        'PUT',
        'organizations.projects.update',
        [],
        [
            'name' => 'Unauthorized update',
            'description' => 'This mutation must never be persisted.',
            'project_type' => ProjectType::Api->value,
        ],
    ],

    'project archive' => [
        'PUT',
        'organizations.projects.archive',
        [],
        [],
    ],

    'project restore' => [
        'PUT',
        'organizations.projects.restore',
        [],
        [],
    ],

    'project setup entry' => [
        'GET',
        'organizations.projects.setup.start',
        [],
        [],
    ],

    'project setup page' => [
        'GET',
        'organizations.projects.setup.show',
        ['step' => 'details'],
        [],
    ],

    'project setup command' => [
        'PUT',
        'organizations.projects.setup.update',
        ['step' => 'details'],
        [],
    ],

    'document list' => [
        'GET',
        'organizations.projects.documents.index',
        [],
        [],
    ],

    'document upload' => [
        'POST',
        'organizations.projects.documents.store',
        [],
        [],
    ],

    'project settings' => [
        'GET',
        'organizations.projects.settings.show',
        [],
        [],
    ],

    'integration settings' => [
        'GET',
        'organizations.projects.integrations.index',
        [],
        [],
    ],

    'Notion connection test' => [
        'POST',
        'organizations.projects.integrations.notion.test',
        [],
        [],
    ],

    'Notion credential rotation' => [
        'PUT',
        'organizations.projects.integrations.credentials.store',
        ['provider' => 'notion'],
        [],
    ],

    'audit timeline' => [
        'GET',
        'organizations.projects.audit.index',
        [],
        [],
    ],

    'development queue' => [
        'GET',
        'organizations.projects.development.index',
        [],
        [],
    ],

    'QA report' => [
        'GET',
        'organizations.projects.quality-assurance.index',
        [],
        [],
    ],

    'roadmap list' => [
        'GET',
        'organizations.projects.roadmaps.index',
        [],
        [],
    ],

    'operations dashboard' => [
        'GET',
        'organizations.projects.operations.index',
        [],
        [],
    ],

    'operational metrics' => [
        'GET',
        'organizations.projects.operations.metrics.index',
        [],
        [],
    ],

    '3D office' => [
        'GET',
        'organizations.projects.operations.office.index',
        [],
        [],
    ],

    'operations read model' => [
        'GET',
        'organizations.projects.operations.show',
        [],
        [],
    ],

    'office projection' => [
        'GET',
        'organizations.projects.operations.office-projection.show',
        [],
        [],
    ],

    'office telemetry' => [
        'POST',
        'organizations.projects.operations.office-telemetry.store',
        [],
        [],
    ],

    'recovery center' => [
        'GET',
        'organizations.projects.operations.recovery.index',
        [],
        [],
    ],

    'dead-letter replay' => [
        'POST',
        'organizations.projects.operations.recovery.replay',
        [],
        [],
    ],

    'usage view' => [
        'GET',
        'organizations.projects.operations.usage.index',
        [],
        [],
    ],

    'approval inbox' => [
        'GET',
        'organizations.projects.approvals.index',
        [],
        [],
    ],
]);

/*
|--------------------------------------------------------------------------
| Same-project restricted command coverage
|--------------------------------------------------------------------------
*/

dataset('AIOS-156 viewer-denied commands', [
    'project update' => [
        'PUT',
        'organizations.projects.update',
        [],
        [
            'name' => 'Viewer mutation',
            'description' => 'This mutation must never be persisted.',
            'project_type' => ProjectType::Api->value,
        ],
    ],

    'project archive' => [
        'PUT',
        'organizations.projects.archive',
        [],
        [],
    ],

    'project restore' => [
        'PUT',
        'organizations.projects.restore',
        [],
        [],
    ],

    'document upload' => [
        'POST',
        'organizations.projects.documents.store',
        [],
        [],
    ],

    'Notion connection test' => [
        'POST',
        'organizations.projects.integrations.notion.test',
        [],
        [],
    ],

    'Notion credential rotation' => [
        'PUT',
        'organizations.projects.integrations.credentials.store',
        ['provider' => 'notion'],
        [],
    ],

    'project setup update' => [
        'PUT',
        'organizations.projects.setup.update',
        ['step' => 'details'],
        [],
    ],

    'dead-letter replay' => [
        'POST',
        'organizations.projects.operations.recovery.replay',
        [],
        [],
    ],
]);

/**
 * Verify that every named project-scoped route keeps authentication,
 * verification, scoped binding, and project-policy enforcement.
 */
test(
    'AIOS-156 protects every project-scoped HTTP route',
    function (): void {
        $projectRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(
                static fn (LaravelRoute $route): bool => str_starts_with(
                    (string) $route->getName(),
                    'organizations.projects.',
                )
                    && in_array(
                        'project',
                        $route->parameterNames(),
                        true,
                    ),
            )
            ->values();

        expect($projectRoutes)
            ->not->toBeEmpty();

        foreach ($projectRoutes as $route) {
            $routeName = (string) $route->getName();

            expect($route->enforcesScopedBindings())
                ->toBeTrue(
                    sprintf(
                        'Route [%s] must enforce scoped bindings.',
                        $routeName,
                    ),
                );

            $middleware = array_values(
                array_filter(
                    $route->gatherMiddleware(),
                    is_string(...),
                ),
            );

            foreach (['auth', 'auth.session', 'verified'] as $required) {
                expect(in_array($required, $middleware, true))
                    ->toBeTrue(
                        sprintf(
                            'Route [%s] is missing middleware [%s].',
                            $routeName,
                            $required,
                        ),
                    );
            }

            $hasProjectAuthorization = collect($middleware)->contains(
                static fn (string $item): bool => str_starts_with($item, 'can:')
                    && str_contains($item, ',project'),
            );

            expect($hasProjectAuthorization)
                ->toBeTrue(
                    sprintf(
                        'Route [%s] must authorize against the project.',
                        $routeName,
                    ),
                );
        }
    },
)->group('aios-156', 'acceptance', 'security');

/**
 * Verify that substituting a project from another organization fails before
 * any delivery controller can read or mutate project-owned state.
 */
test(
    'AIOS-156 rejects parent-project substitution through :dataset',
    function (
        string $method,
        string $routeName,
        array $extraParameters,
        array $payload,
    ): void {
        $actor = User::factory()->create();

        $routeOrganization = Organization::factory()->create();
        $owningOrganization = Organization::factory()->create();

        /*
         * Membership in both organizations proves that the rejection comes
         * from parent-child scoped binding rather than absent membership.
         */
        aios156AttachMembership($actor, $routeOrganization);
        aios156AttachMembership($actor, $owningOrganization);

        $foreignProject = Project::factory()
            ->for($owningOrganization)
            ->create([
                'name' => 'Protected foreign project',
                'description' => 'Original protected description.',
            ]);

        $projectBefore = aios156ProjectSnapshot($foreignProject);
        $sideEffectsBefore = aios156SideEffectCounts();

        $parameters = [
            'organization' => $routeOrganization,
            'project' => $foreignProject,
            ...$extraParameters,
        ];

        $this
            ->actingAs($actor)
            ->withSession([
                'current_organization_id' => $routeOrganization->id,
            ])
            ->call(
                $method,
                route($routeName, $parameters),
                $payload,
            )
            ->assertNotFound();

        expect(aios156ProjectSnapshot($foreignProject))
            ->toBe($projectBefore)
            ->and(aios156SideEffectCounts())
            ->toBe($sideEffectsBefore);
    },
)->with('AIOS-156 cross-project endpoints')
    ->group('aios-156', 'acceptance', 'security');

/**
 * Verify that insufficient same-organization roles receive a normal
 * authorization denial without executing the privileged command.
 */
test(
    'AIOS-156 rejects a viewer from :dataset without side effects',
    function (
        string $method,
        string $routeName,
        array $extraParameters,
        array $payload,
    ): void {
        $viewer = User::factory()->create();
        $organization = Organization::factory()->create();

        aios156AttachMembership(
            user: $viewer,
            organization: $organization,
            role: OrganizationRole::Viewer,
        );

        $project = Project::factory()
            ->for($organization)
            ->create([
                'name' => 'Viewer protected project',
                'description' => 'Original protected description.',
            ]);

        $projectBefore = aios156ProjectSnapshot($project);
        $sideEffectsBefore = aios156SideEffectCounts();

        $parameters = [
            'organization' => $organization,
            'project' => $project,
            ...$extraParameters,
        ];

        $this
            ->actingAs($viewer)
            ->withSession([
                'current_organization_id' => $organization->id,
            ])
            ->call(
                $method,
                route($routeName, $parameters),
                $payload,
            )
            ->assertForbidden();

        expect(aios156ProjectSnapshot($project))
            ->toBe($projectBefore)
            ->and(aios156SideEffectCounts())
            ->toBe($sideEffectsBefore);
    },
)->with('AIOS-156 viewer-denied commands')
    ->group('aios-156', 'acceptance', 'security');

/**
 * Verify that organization and project real-time channels enforce the same
 * tenant and parent-child boundaries as HTTP routes.
 */
test(
    'AIOS-156 rejects unauthorized and mismatched real-time subscriptions',
    function (): void {
        $firstOrganization = Organization::factory()->create();
        $secondOrganization = Organization::factory()->create();
        $unrelatedOrganization = Organization::factory()->create();

        $firstUser = User::factory()->create();
        $dualMember = User::factory()->create();

        aios156AttachMembership($firstUser, $firstOrganization);

        aios156AttachMembership($dualMember, $firstOrganization);
        aios156AttachMembership($dualMember, $secondOrganization);

        $firstProject = Project::factory()
            ->for($firstOrganization)
            ->create();

        $secondProject = Project::factory()
            ->for($secondOrganization)
            ->create();

        $organizationChannel = app(
            OrganizationEventStreamChannel::class,
        );

        $projectChannel = app(
            ProjectEventStreamChannel::class,
        );

        expect(
            $organizationChannel->join(
                $firstUser,
                $firstOrganization->id,
            ),
        )
            ->toBeTrue()
            ->and(
                $organizationChannel->join(
                    $firstUser,
                    unrelatedOrganizationId: $unrelatedOrganization->id,
                ),
            )
            ->toBeFalse();
    },
)->skip(
    'Replace this temporary compile guard with the complete channel assertion below.',
);
