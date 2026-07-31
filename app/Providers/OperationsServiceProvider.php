<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Boots the project operations query module.
 */
final class OperationsServiceProvider extends ServiceProvider
{
    /**
     * Register operations application services.
     *
     * The current read model uses concrete dependencies and does not require
     * container interface bindings.
     */
    public function register(): void
    {
        //
    }

    /**
     * Load authenticated project operations routes.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(
            base_path('routes/operations.php'),
        );
    }
}
