<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Boots the notification module's HTTP routes.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    /**
     * Register notification module services.
     *
     * The current application services are concrete and require no additional
     * container bindings.
     */
    public function register(): void
    {
        //
    }

    /**
     * Load the authenticated in-app notification routes.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(
            base_path('routes/notifications.php'),
        );
    }
}
