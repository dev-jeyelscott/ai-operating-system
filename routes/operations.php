<?php

declare(strict_types=1);

use App\Http\Controllers\Approvals\ProjectApprovalInboxController;
use App\Http\Controllers\Operations\ProjectOfficeProjectionController;
use App\Http\Controllers\Operations\ProjectOperationsDashboardController;
use App\Http\Controllers\Operations\ProjectRecoveryCenterController;
use App\Http\Controllers\Operations\ProjectUsageController;
use App\Http\Controllers\Operations\ReplayProjectDeadLetterController;
use Illuminate\Support\Facades\Route;

/*
 * Keep operational reads and authorized recovery commands inside the same
 * tenant-scoped route boundary.
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
            '/operations/office-projection',
            ProjectOfficeProjectionController::class,
        )
            ->can('view', 'project')
            ->name('operations.office-projection.show');

        Route::get(
            '/operations/recovery',
            ProjectRecoveryCenterController::class,
        )
            ->can('view', 'project')
            ->name('operations.recovery.index');

        Route::post(
            '/operations/recovery/dead-letters/replay',
            ReplayProjectDeadLetterController::class,
        )
            ->can('approve', 'project')
            ->name('operations.recovery.replay');

        Route::get(
            '/operations/usage',
            ProjectUsageController::class,
        )
            ->can('view', 'project')
            ->name('operations.usage.index');

        Route::get(
            '/approvals',
            ProjectApprovalInboxController::class,
        )
            ->can('view', 'project')
            ->name('approvals.index');
    });
