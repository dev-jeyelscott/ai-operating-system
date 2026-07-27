<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Idempotency\Contracts\IdempotencyKeyService;
use App\Infrastructure\Bus\IdempotentCommandBus;
use App\Infrastructure\Idempotency\DatabaseIdempotencyKeyService;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers durable command idempotency and decorates the command bus.
 */
final class IdempotencyServiceProvider extends ServiceProvider
{
    /**
     * Register the idempotency service and command-bus decorator.
     */
    public function register(): void
    {
        $this->app->singleton(
            IdempotencyKeyService::class,
            function (Application $app): IdempotencyKeyService {
                $config = $app->make(ConfigRepository::class);
                $cacheStore = $config->get(
                    'idempotency.cache_store',
                );

                return new DatabaseIdempotencyKeyService(
                    cache: $app->make(CacheFactory::class),
                    cacheStore: is_string($cacheStore)
                        && $cacheStore !== ''
                            ? $cacheStore
                            : null,
                    lockWaitSeconds: max(
                        1,
                        (int) $config->get(
                            'idempotency.lock_wait_seconds',
                            5,
                        ),
                    ),
                    processingTtlSeconds: max(
                        1,
                        (int) $config->get(
                            'idempotency.processing_ttl_seconds',
                            900,
                        ),
                    ),
                    retentionSeconds: max(
                        1,
                        (int) $config->get(
                            'idempotency.retention_seconds',
                            86400,
                        ),
                    ),
                );
            },
        );

        /*
         * CommandBusServiceProvider owns the original bus implementation.
         * This provider adds one decorator without replacing handler mapping.
         */
        $this->app->extend(
            CommandBus::class,
            static function (
                CommandBus $commandBus,
                Application $app,
            ): CommandBus {
                return new IdempotentCommandBus(
                    inner: $commandBus,
                    idempotencyKeys: $app->make(
                        IdempotencyKeyService::class,
                    ),
                );
            },
        );
    }
}
