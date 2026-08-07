<?php

declare(strict_types=1);

use App\Http\Controllers\Approvals\DecideCodexApprovalController;
use App\Http\Controllers\Approvals\ProjectApprovalInboxController;
use App\Http\Controllers\Approvals\ShowCodexApprovalController;
use App\Http\Controllers\Operations\ProjectOfficeController;
use App\Http\Controllers\Operations\ProjectOfficeProjectionController;
use App\Http\Controllers\Operations\ProjectOperationalMetricsController;
use App\Http\Controllers\Operations\ProjectOperationsDashboardController;
use App\Http\Controllers\Operations\ProjectOperationsReadModelController;
use App\Http\Controllers\Operations\ProjectRecoveryCenterController;
use App\Http\Controllers\Operations\ProjectUsageController;
use App\Http\Controllers\Operations\ReplayProjectDeadLetterController;
use App\Http\Controllers\Operations\StoreOfficeRenderingTelemetryController;
use Illuminate\Support\Facades\Route;

/*
 * Keep every operations endpoint inside the authenticated and tenant-scoped
 * organization/project boundary.
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
            '/operations/metrics',
            ProjectOperationalMetricsController::class,
        )
            ->can('view', 'project')
            ->name('operations.metrics.index');

        Route::get(
            '/operations/office',
            ProjectOfficeController::class,
        )
            ->can('view', 'project')
            ->name('operations.office.index');

        Route::get(
            '/operations/read-model',
            ProjectOperationsReadModelController::class,
        )
            ->can('view', 'project')
            ->name('operations.show');

        Route::get(
            '/operations/office-projection',
            ProjectOfficeProjectionController::class,
        )
            ->can('view', 'project')
            ->name('operations.office-projection.show');

        /*
         * Accept bounded, privacy-safe renderer telemetry.
         *
         * This endpoint records presentation health only. It never advances
         * workflow state or writes audit/evidence records.
         */
        Route::post(
            '/operations/office-telemetry',
            StoreOfficeRenderingTelemetryController::class,
        )
            ->middleware('throttle:30,1')
            ->can('view', 'project')
            ->name('operations.office-telemetry.store');

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

        /*
         * Resolve one Codex provider request to its authoritative generic
         * application approval within the same organization/project boundary.
         */
        Route::get(
            '/approvals/codex/{codexApprovalRequest}',
            ShowCodexApprovalController::class,
        )
            ->can('view', 'project')
            ->name('approvals.codex.show');

        /*
         * Apply human decisions only through the existing project approval
         * permission and the Codex approval bridge.
         */
        Route::post(
            '/approvals/codex/{codexApprovalRequest}/decision',
            DecideCodexApprovalController::class,
        )
            ->middleware('throttle:30,1')
            ->can('approve', 'project')
            ->name('approvals.codex.decide');
    });
