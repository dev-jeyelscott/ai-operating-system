<?php

declare(strict_types=1);

use App\Application\Notifications\ListUserNotifications;
use App\Broadcasting\OrganizationEventStreamChannel;
use App\Broadcasting\ProjectEventStreamChannel;
use App\Models\NotificationEvent;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

/**
 * Return normalized middleware names assigned to one route.
 *
 * Parameterized middleware such as "can:view,project" are normalized to "can".
 *
 * @return list<string>
 */
function aios137MiddlewareNames(LaravelRoute $route): array
{
    $names = [];

    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }

        $names[] = explode(':', $middleware, 2)[0];
    }

    return array_values(array_unique($names));
}

/**
 * Determine whether a normalized middleware list contains an alias or class.
 *
 * @param  list<string>  $middleware
 */
function aios137HasMiddleware(
    array $middleware,
    string $alias,
    string $middlewareClass,
): bool {
    return in_array($alias, $middleware, true)
        || in_array($middlewareClass, $middleware, true);
}

/**
 * Create an organization owner and one project for tenant-audit tests.
 *
 * @return array{
 *     user: User,
 *     organization: Organization,
 *     project: Project
 * }
 */
function aios137CreateTenantContext(): array
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

    return compact('user', 'organization', 'project');
}

it(
    'requires authentication authorization and tenant scoping on organization routes',
    function (): void {
        /** @var array<string, list<string>> $violations */
        $violations = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_contains($uri, '{organization}')) {
                continue;
            }

            $middleware = aios137MiddlewareNames($route);
            $routeViolations = [];

            if (! aios137HasMiddleware(
                $middleware,
                'auth',
                Authenticate::class,
            )) {
                $routeViolations[] = 'missing auth middleware';
            }

            if (! aios137HasMiddleware(
                $middleware,
                'auth.session',
                AuthenticateSession::class,
            )) {
                $routeViolations[] = 'missing authenticated-session middleware';
            }

            if (! aios137HasMiddleware(
                $middleware,
                'verified',
                EnsureEmailIsVerified::class,
            )) {
                $routeViolations[] = 'missing verified-email middleware';
            }

            if (! aios137HasMiddleware(
                $middleware,
                'can',
                Authorize::class,
            )) {
                $routeViolations[] = 'missing server-side authorization middleware';
            }

            if (
                str_contains($uri, '{project}')
                && ! $route->enforcesScopedBindings()
            ) {
                $routeViolations[] = 'missing scoped model bindings';
            }

            if ($routeViolations === []) {
                continue;
            }

            $identifier = $route->getName()
                ?? sprintf(
                    '%s %s',
                    implode('|', $route->methods()),
                    $uri,
                );

            $violations[$identifier] = $routeViolations;
        }

        expect($violations)->toBe([]);
    },
);

it(
    'does not resolve a project through another organization',
    function (): void {
        $tenantA = aios137CreateTenantContext();

        $organizationB = Organization::factory()->create();

        $projectB = Project::factory()
            ->for($organizationB)
            ->create();

        $this
            ->actingAs($tenantA['user'])
            ->get(route(
                'organizations.projects.show',
                [
                    'organization' => $tenantA['organization'],
                    'project' => $projectB,
                ],
            ))
            ->assertNotFound();
    },
);

it(
    'does not disclose a valid project to a non-member',
    function (): void {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $project = Project::factory()
            ->for($organization)
            ->create();

        $this
            ->actingAs($user)
            ->get(route(
                'organizations.projects.show',
                compact('organization', 'project'),
            ))
            ->assertNotFound();
    },
);

it(
    'keeps notification inbox results inside the requested organization',
    function (): void {
        $tenantA = aios137CreateTenantContext();

        $organizationB = Organization::factory()->create();

        $projectB = Project::factory()
            ->for($organizationB)
            ->create();

        $eventA = NotificationEvent::factory()
            ->forProject($tenantA['project'])
            ->create();

        $eventB = NotificationEvent::factory()
            ->forProject($projectB)
            ->create();

        NotificationRecipient::factory()
            ->forEvent($eventA)
            ->forRecipient($tenantA['user'])
            ->create();

        /*
         * The same user may belong to multiple organizations. The inbox must
         * still return only the explicitly selected organization.
         */
        NotificationRecipient::factory()
            ->forEvent($eventB)
            ->forRecipient($tenantA['user'])
            ->create();

        $inbox = app(ListUserNotifications::class)->handle(
            organizationId: $tenantA['organization']->id,
            recipientUserId: $tenantA['user']->id,
            limit: 50,
        );

        expect(array_column($inbox['items'], 'eventId'))
            ->toBe([$eventA->id]);
    },
);

it(
    'authorizes organization and project streams independently',
    function (): void {
        $tenantA = aios137CreateTenantContext();

        $organizationB = Organization::factory()->create();

        $projectB = Project::factory()
            ->for($organizationB)
            ->create();

        $organizationChannel = new OrganizationEventStreamChannel;
        $projectChannel = new ProjectEventStreamChannel;

        expect($organizationChannel->join(
            $tenantA['user'],
            $tenantA['organization']->id,
        ))->toBeTrue();

        expect($organizationChannel->join(
            $tenantA['user'],
            $organizationB->id,
        ))->toBeFalse();

        expect($projectChannel->join(
            $tenantA['user'],
            $tenantA['organization']->id,
            $tenantA['project']->id,
        ))->toBeTrue();

        expect($projectChannel->join(
            $tenantA['user'],
            $organizationB->id,
            $projectB->id,
        ))->toBeFalse();

        /*
         * Supplying organization A with project B must also fail. This covers
         * a manipulated private-channel name containing mixed tenant IDs.
         */
        expect($projectChannel->join(
            $tenantA['user'],
            $tenantA['organization']->id,
            $projectB->id,
        ))->toBeFalse();
    },
);
