<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Health\HealthController;
use App\Http\Controllers\Health\ReadinessController;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\OrganizationDashboardController;
use App\Http\Controllers\Organizations\SwitchCurrentOrganizationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'auth.session', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    Route::post('/organizations', OrganizationController::class)
        ->name('organizations.store');

    Route::prefix('/organizations/{organization}')
        ->name('organizations.')
        ->group(function (): void {
            Route::get('/dashboard', OrganizationDashboardController::class)
                ->can('view', 'organization')
                ->name('dashboard');

            Route::put('/current', SwitchCurrentOrganizationController::class)
                ->can('view', 'organization')
                ->name('current.update');
        });
});

Route::get('/health', HealthController::class)
    ->name('health');

Route::get('/ready', ReadinessController::class)
    ->name('ready');

require __DIR__.'/settings.php';
