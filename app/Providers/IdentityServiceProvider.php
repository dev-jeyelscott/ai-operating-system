<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Identity\Contracts\OrganizationRepository;
use App\Infrastructure\Persistence\Repositories\Identity\EloquentOrganizationRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Identity module infrastructure adapters.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    /**
     * Bind application contracts to infrastructure implementations.
     */
    public function register(): void
    {
        $this->app->bind(
            OrganizationRepository::class,
            EloquentOrganizationRepository::class,
        );
    }
}
