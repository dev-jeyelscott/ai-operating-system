<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Events\Contracts\DomainEventConsumerRegistry;
use App\Application\Events\Contracts\DomainEventConsumptionStore;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Application\Events\Contracts\OutboxDispatchStore;
use App\Application\Events\Contracts\OutboxTransport;
use App\Console\Commands\DispatchOutboxMessagesCommand;
use App\Console\Commands\ListDeadLettersCommand;
use App\Console\Commands\ReplayDeadLetterCommand;
use App\Infrastructure\Events\ConfiguredDomainEventConsumerRegistry;
use App\Infrastructure\Events\EloquentDomainEventConsumptionStore;
use App\Infrastructure\Events\EloquentOutboxDispatchStore;
use App\Infrastructure\Events\LaravelOutboxTransport;
use App\Infrastructure\Persistence\Repositories\Events\EloquentDomainEventOutbox as EventsEloquentDomainEventOutbox;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * Registers domain-event persistence, dispatch, consumer, and recovery
 * infrastructure.
 */
final class DomainEventsServiceProvider extends ServiceProvider
{
    /**
     * Bind application contracts to Laravel and PostgreSQL adapters.
     */
    public function register(): void
    {
        /*
         * AIOS-050 transactional persistence binding.
         */
        $this->app->bind(
            DomainEventOutbox::class,
            EventsEloquentDomainEventOutbox::class,
        );

        /*
         * AIOS-051 dispatcher and consumer bindings.
         */
        $this->app->bind(
            OutboxDispatchStore::class,
            EloquentOutboxDispatchStore::class,
        );

        $this->app->bind(
            OutboxTransport::class,
            LaravelOutboxTransport::class,
        );

        $this->app->bind(
            DomainEventConsumptionStore::class,
            EloquentDomainEventConsumptionStore::class,
        );

        $this->app->singleton(
            DomainEventConsumerRegistry::class,
            function (
                Application $application,
            ): DomainEventConsumerRegistry {
                $consumerClasses = config(
                    'domain-events.consumers',
                    [],
                );

                if (! is_array($consumerClasses)) {
                    throw new LogicException(
                        'Configured domain-event consumers must be an array.',
                    );
                }

                /** @var list<class-string> $consumerClasses */
                return new ConfiguredDomainEventConsumerRegistry(
                    container: $application,
                    consumerClasses: $consumerClasses,
                );
            },
        );

        /*
         * AIOS-056 reuses Laravel's configured durable failed-job provider
         * instead of introducing a second failed queue-job repository.
         */
        $this->app->alias(
            'queue.failer',
            FailedJobProviderInterface::class,
        );

        $this->commands([
            DispatchOutboxMessagesCommand::class,
            ListDeadLettersCommand::class,
            ReplayDeadLetterCommand::class,
        ]);
    }
}
