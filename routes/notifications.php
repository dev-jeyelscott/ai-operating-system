<?php

declare(strict_types=1);

use App\Http\Controllers\Notifications\MarkNotificationAsReadController;
use App\Http\Controllers\Notifications\OpenNotificationController;
use Illuminate\Support\Facades\Route;

/*
 * Notification routes are isolated from project routes because an
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
        /*
         * Open an actionable notification through a POST so browser prefetch,
         * crawlers, and previews cannot accidentally mark it as read.
         */
        Route::post(
            '/notifications/{notificationRecipient}/open',
            OpenNotificationController::class,
        )
            ->whereUlid('notificationRecipient')
            ->middleware('throttle:120,1')
            ->can('view', 'organization')
            ->name('notifications.open');

        /*
         * Retain the read-only acknowledgement endpoint for callers that need
         * to mark a notification read without navigation.
         */
        Route::patch(
            '/notifications/{notificationRecipient}/read',
            MarkNotificationAsReadController::class,
        )
            ->whereUlid('notificationRecipient')
            ->middleware('throttle:120,1')
            ->can('view', 'organization')
            ->name('notifications.read');
    });
