<?php

declare(strict_types=1);

use App\Http\Controllers\Notifications\MarkNotificationAsReadController;
use Illuminate\Support\Facades\Route;

/*
 * Notification routes are isolated from project-specific routes because an
 * organization-wide notification may not belong to one project.
 */
Route::middleware([
    'web',
    'auth',
    'auth.session',
    'verified',
])
    ->prefix('organizations/{organization}')
    ->name('organizations.')
    ->scopeBindings()
    ->group(function (): void {
        Route::patch(
            '/notifications/{notificationRecipient}/read',
            MarkNotificationAsReadController::class,
        )
            ->whereUlid('notificationRecipient')
            ->middleware('throttle:120,1')
            ->can('view', 'organization')
            ->name('notifications.read');
    });
