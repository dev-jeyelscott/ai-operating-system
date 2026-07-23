<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Projects\Contracts\ProjectRepository;
use App\Infrastructure\Persistence\Repositories\Projects\EloquentProjectRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Projects module infrastructure adapters.
 */
final class ProjectsServiceProvider extends ServiceProvider
{
    /**
     * Bind project application contracts to infrastructure implementations.
     */
    public function register(): void
    {
        $this->app->bind(
            ProjectRepository::class,
            EloquentProjectRepository::class,
        );
    }
}
