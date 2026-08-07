<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\RebuildOfficeProjectionsCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the project operations and office projection module.
 */
final class OperationsServiceProvider extends ServiceProvider
{
    /**
     * Register operations console commands.
     */
    public function register(): void
    {
        $this->commands([
            RebuildOfficeProjectionsCommand::class,
        ]);
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
