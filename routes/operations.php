<?php

declare(strict_types=1);

use App\Http\Controllers\Approvals\ProjectApprovalInboxController;
use App\Http\Controllers\Operations\ProjectOperationsDashboardController;
use Illuminate\Support\Facades\Route;

/*
 * Keep project operations pages inside the authenticated, tenant-scoped route
 * boundary. Scoped bindings prevent a project from another organization from
 * being resolved under the current organization URL.
 */
Route::middleware(['auth', 'auth.session', 'verified'])
    ->prefix('/organizations/{organization}/projects/{project}')
    ->name('organizations.projects.')
    ->scopeBindings()
    ->group(function (): void {
        Route::get(
            '/operations',
            ProjectOperationsDashboardController::class,
        )
            ->can('view', 'project')
            ->name('operations.index');

        Route::get(
            '/approvals',
            ProjectApprovalInboxController::class,
        )
            ->can('view', 'project')
            ->name('approvals.index');
    });
