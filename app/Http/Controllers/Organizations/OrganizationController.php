<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Application\Identity\CreateOrganization;
use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Handles organization creation delivery concerns.
 */
final class OrganizationController
{
    /**
     * Create an organization for the authenticated user.
     */
    public function __invoke(
        StoreOrganizationRequest $request,
        CreateOrganization $createOrganization,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $organization = $createOrganization->handle(
            ownerUserId: $user->id,
            name: (string) $request->validated('name'),
        );

        return to_route('dashboard')
            ->with('status', 'organization-created')
            ->with('organization_id', $organization->id);
    }
}
