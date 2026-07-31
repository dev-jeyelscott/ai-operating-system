<?php

declare(strict_types=1);

use App\Http\Controllers\Approvals\ProjectApprovalInboxController;
use App\Http\Controllers\Operations\ProjectOfficeController;
use App\Http\Controllers\Operations\ProjectOfficeProjectionController;
use App\Http\Controllers\Operations\ProjectOperationsDashboardController;
use App\Http\Controllers\Operations\ProjectOperationsReadModelController;
use App\Http\Controllers\Operations\ProjectRecoveryCenterController;
use App\Http\Controllers\Operations\ProjectUsageController;
use App\Http\Controllers\Operations\ReplayProjectDeadLetterController;
use Illuminate\Support\Facades\Route;

/*
 * Keep every operations endpoint inside the authenticated and tenant-scoped
 * organization/project boundary.
 *
 * Scoped bindings prevent a project from being resolved beneath an
 * organization that does not own it.
 */
Route::middleware(['auth', 'auth.session', 'verified'])
    ->prefix('/organizations/{organization}/projects/{project}')
    ->name('organizations.projects.')
    ->scopeBindings()
    ->group(function (): void {
        /*
         * Render the accessible, non-3D operational dashboard introduced by
         * AIOS-120.
         */
        Route::get(
            '/operations',
            ProjectOperationsDashboardController::class,
        )
            ->can('view', 'project')
            ->name('operations.index');

        /*
         * Render the lazy-loaded interactive office while preserving the
         * accessible operational dashboard as the equivalent fallback.
         */
        Route::get(
            '/operations/office',
            ProjectOfficeController::class,
        )
            ->can('view', 'project')
            ->name('operations.office.index');

        /*
         * Preserve the AIOS-117 machine-readable operations contract.
         */
        Route::get(
            '/operations/read-model',
            ProjectOperationsReadModelController::class,
        )
            ->can('view', 'project')
            ->name('operations.show');

        /*
         * Preserve the AIOS-118 machine-readable office projection contract.
         */
        Route::get(
            '/operations/office-projection',
            ProjectOfficeProjectionController::class,
        )
            ->can('view', 'project')
            ->name('operations.office-projection.show');

        /*
         * Render the AIOS-122 blocker and recovery center.
         */
        Route::get(
            '/operations/recovery',
            ProjectRecoveryCenterController::class,
        )
            ->can('view', 'project')
            ->name('operations.recovery.index');

        /*
         * Permit only project approvers to replay one project-owned dead letter.
         */
        Route::post(
            '/operations/recovery/dead-letters/replay',
            ReplayProjectDeadLetterController::class,
        )
            ->can('approve', 'project')
            ->name('operations.recovery.replay');

        /*
         * Render the AIOS-123 usage and separated-cost view.
         */
        Route::get(
            '/operations/usage',
            ProjectUsageController::class,
        )
            ->can('view', 'project')
            ->name('operations.usage.index');

        /*
         * Render the actionable project approval inbox introduced by AIOS-121.
         */
        Route::get(
            '/approvals',
            ProjectApprovalInboxController::class,
        )
            ->can('view', 'project')
            ->name('approvals.index');
    });
