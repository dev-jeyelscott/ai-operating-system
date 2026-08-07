<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Audit\Contracts\AuditEventRepository;
use App\Application\Audit\Contracts\AuditTimelineQuery;
use App\Infrastructure\Persistence\Queries\Audit\EloquentAuditTimelineQuery;
use App\Infrastructure\Persistence\Repositories\Audit\EloquentAuditEventRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Audit module persistence and query adapters.
 */
final class AuditServiceProvider extends ServiceProvider
{
    /**
     * Bind Audit module application contracts to infrastructure adapters.
     */
    public function register(): void
    {
        $this->app->bind(
            AuditEventRepository::class,
            EloquentAuditEventRepository::class,
        );

        $this->app->bind(
            AuditTimelineQuery::class,
            EloquentAuditTimelineQuery::class,
        );
    }
}
