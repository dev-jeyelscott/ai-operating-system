<?php

declare(strict_types=1);

use App\Http\Controllers\Operations\ProjectOfficeProjectionController;
use App\Http\Controllers\Operations\ProjectOperationsController;
use Illuminate\Support\Facades\Route;

/*
 * Operational read routes are isolated from mutation routes so dashboard and
 * office clients can depend on stable, tenant-authorized query contracts.
 */
Route::middleware([
    'web',
    'auth',
    'auth.session',
    'verified',
])
    ->prefix(
        'organizations/{organization}/projects/{project}',
    )
    ->name('organizations.projects.')
    ->scopeBindings()
    ->group(function (): void {
        Route::get(
            '/operations',
            ProjectOperationsController::class,
        )
            ->can('view', 'project')
            ->name('operations.show');

        Route::get(
            '/operations/office-projection',
            ProjectOfficeProjectionController::class,
        )
            ->can('view', 'project')
            ->name('operations.office-projection.show');
    });
