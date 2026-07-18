<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Changes the authenticated session's organization navigation preference.
 */
final class SwitchCurrentOrganizationController
{
    /**
     * Store the selected organization and redirect to its scoped dashboard.
     *
     * Authorization is enforced by the route's organization policy middleware.
     */
    public function __invoke(
        Request $request,
        Organization $organization,
    ): RedirectResponse {
        $request->session()->put(
            'current_organization_id',
            $organization->id,
        );

        return to_route('organizations.dashboard', [
            'organization' => $organization,
        ]);
    }
}
