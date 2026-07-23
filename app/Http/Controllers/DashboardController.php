<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\ResolveOrganizationContext;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resolves the user's default organization-aware dashboard destination.
 */
final readonly class DashboardController
{
    /**
     * Inject the current-organization resolver.
     */
    public function __construct(
        private ResolveOrganizationContext $resolveOrganizationContext,
    ) {}

    /**
     * Show onboarding for users without organizations or redirect to the
     * organization-scoped dashboard.
     */
    public function __invoke(
        Request $request,
    ): RedirectResponse|Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $sessionOrganizationId = $request->session()->get(
            'current_organization_id',
        );

        $context = $this->resolveOrganizationContext->handle(
            userId: $user->id,
            preferredOrganizationId: is_int($sessionOrganizationId)
                ? $sessionOrganizationId
                : null,
        );

        if ($context->currentOrganization === null) {
            return Inertia::render('dashboard');
        }

        $request->session()->put(
            'current_organization_id',
            $context->currentOrganization->id,
        );

        return to_route('organizations.dashboard', [
            'organization' => $context->currentOrganization->slug,
        ]);
    }
}
