<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Render the dashboard within an authorized organization context.
 */
final class OrganizationDashboardController extends Controller
{
    /**
     * Render the scoped dashboard and remember the route organization.
     *
     * The route-bound organization is authoritative. The session only remembers
     * the user's most recently selected organization for future navigation.
     */
    public function __invoke(
        Request $request,
        Organization $organization,
    ): Response {
        $request->session()->put(
            'current_organization_id',
            (int) $organization->getKey(),
        );

        return Inertia::render('dashboard');
    }
}
