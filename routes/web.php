<?php

use App\Http\Controllers\Health\HealthController;
use App\Http\Controllers\Health\ReadinessController;
use App\Http\Controllers\Organizations\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'auth.session', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::post('/organizations', OrganizationController::class)
        ->name('organizations.store');
});

Route::get('/health', HealthController::class)
    ->name('health');

Route::get('/ready', ReadinessController::class)
    ->name('ready');

require __DIR__.'/settings.php';
