<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Audit\Contracts\AuditEventRepository;
use App\Infrastructure\Persistence\Repositories\Audit\EloquentAuditEventRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Audit module infrastructure adapters.
 */
final class AuditServiceProvider extends ServiceProvider
{
    /**
     * Bind audit application contracts to infrastructure implementations.
     */
    public function register(): void
    {
        $this->app->bind(
            AuditEventRepository::class,
            EloquentAuditEventRepository::class,
        );
    }
}
