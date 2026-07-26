<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Events\Contracts\DomainEventOutbox;
use App\Infrastructure\Persistence\Repositories\Events\EloquentDomainEventOutbox;
use Illuminate\Support\ServiceProvider;

/**
 * Registers domain-event and outbox infrastructure adapters.
 */
final class DomainEventsServiceProvider extends ServiceProvider
{
    /**
     * Bind application event contracts to infrastructure implementations.
     */
    public function register(): void
    {
        $this->app->bind(
            DomainEventOutbox::class,
            EloquentDomainEventOutbox::class,
        );
    }
}
