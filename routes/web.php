<?php

use App\Http\Controllers\Health\HealthController;
use App\Http\Controllers\Health\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

Route::get('/health', HealthController::class)
    ->name('health');

Route::get('/ready', ReadinessController::class)
    ->name('ready');

require __DIR__.'/settings.php';
