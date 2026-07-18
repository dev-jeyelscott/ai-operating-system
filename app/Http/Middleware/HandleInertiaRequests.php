<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Identity\Data\OrganizationData;
use App\Application\Identity\ResolveOrganizationContext;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Shares server-authoritative application context with Inertia pages.
 */
final class HandleInertiaRequests extends Middleware
{
    /**
     * The root Blade template loaded for the first Inertia visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Inject the organization-context resolver.
     */
    public function __construct(
        private readonly ResolveOrganizationContext $resolveOrganizationContext,
    ) {}

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define props shared with every Inertia response.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'organizationContext' => $this->organizationContext(
                $request,
            ),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state')
                || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Resolve organization options from authenticated membership data.
     *
     * The organization in the URL takes precedence over the session because
     * the route is the authoritative resource context.
     *
     * @return array{
     *     current: array{id: int, name: string, slug: string}|null,
     *     available: list<array{id: int, name: string, slug: string}>
     * }
     */
    private function organizationContext(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [
                'current' => null,
                'available' => [],
            ];
        }

        $routeOrganization = $request->route('organization');

        $sessionOrganizationId = $request->session()->get(
            'current_organization_id',
        );

        $preferredOrganizationId =
            $routeOrganization instanceof Organization
                ? $routeOrganization->id
                : (
                    is_int($sessionOrganizationId)
                        ? $sessionOrganizationId
                        : null
                );

        $context = $this->resolveOrganizationContext->handle(
            userId: $user->id,
            preferredOrganizationId: $preferredOrganizationId,
        );

        if ($context->currentOrganization === null) {
            $request->session()->forget('current_organization_id');
        } else {
            $request->session()->put(
                'current_organization_id',
                $context->currentOrganization->id,
            );
        }

        return [
            'current' => $context->currentOrganization === null
                ? null
                : $this->serializeOrganization(
                    $context->currentOrganization,
                ),
            'available' => array_map(
                fn (
                    OrganizationData $organization,
                ): array => $this->serializeOrganization($organization),
                $context->availableOrganizations,
            ),
        ];
    }

    /**
     * Convert an organization DTO into its minimal public Inertia payload.
     *
     * @return array{id: int, name: string, slug: string}
     */
    private function serializeOrganization(
        OrganizationData $organization,
    ): array {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
        ];
    }
}
