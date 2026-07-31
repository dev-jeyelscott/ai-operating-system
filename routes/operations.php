<?php

declare(strict_types=1);

use App\Http\Controllers\Operations\ProjectOperationsController;
use Illuminate\Support\Facades\Route;

/*
 * Operational read routes are isolated from mutation routes so the future
 * dashboard and office projection can depend on a stable query contract.
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
    });
