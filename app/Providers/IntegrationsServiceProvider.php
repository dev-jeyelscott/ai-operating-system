<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Integrations\Contracts\IntegrationCircuitBreaker;
use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Application\Integrations\Contracts\NotionConnectionGateway;
use App\Application\Integrations\Contracts\NotionPublicationClient;
use App\Application\Integrations\Contracts\ProjectIntegrationSnapshotReader;
use App\Infrastructure\Integrations\CacheIntegrationCircuitBreaker;
use App\Infrastructure\Integrations\LaravelIntegrationCredentialCipher;
use App\Infrastructure\Integrations\Notion\CircuitBreakingNotionConnectionGateway;
use App\Infrastructure\Integrations\Notion\CircuitBreakingNotionPublicationClient;
use App\Infrastructure\Integrations\Notion\HttpNotionConnectionGateway;
use App\Infrastructure\Integrations\Notion\HttpNotionPublicationClient;
use App\Infrastructure\Integrations\Notion\NotionCircuitScope;
use App\Infrastructure\Persistence\ReadModels\Integrations\EloquentProjectIntegrationSnapshotReader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Registers integration infrastructure adapters and resilience policies.
 */
final class IntegrationsServiceProvider extends ServiceProvider
{
    /**
     * Bind integration application contracts to infrastructure adapters.
     */
    public function register(): void
    {
        $this->app->bind(
            IntegrationCredentialCipher::class,
            LaravelIntegrationCredentialCipher::class,
        );

        $this->app->singleton(
            IntegrationCircuitBreaker::class,
            CacheIntegrationCircuitBreaker::class,
        );

        $this->app->singleton(
            NotionCircuitScope::class,
        );

        /*
         * Keep the raw HTTP adapters resolvable separately so the application
         * contracts can be decorated without modifying provider HTTP logic.
         */
        $this->app->singleton(
            HttpNotionConnectionGateway::class,
        );

        $this->app->singleton(
            HttpNotionPublicationClient::class,
        );

        $this->app->bind(
            NotionConnectionGateway::class,
            fn (
                Application $application,
            ): NotionConnectionGateway => new CircuitBreakingNotionConnectionGateway(
                inner: $application->make(
                    HttpNotionConnectionGateway::class,
                ),
                circuitBreaker: $application->make(
                    IntegrationCircuitBreaker::class,
                ),
                scope: $application->make(
                    NotionCircuitScope::class,
                ),
            ),
        );

        $this->app->bind(
            NotionPublicationClient::class,
            fn (
                Application $application,
            ): NotionPublicationClient => new CircuitBreakingNotionPublicationClient(
                inner: $application->make(
                    HttpNotionPublicationClient::class,
                ),
                circuitBreaker: $application->make(
                    IntegrationCircuitBreaker::class,
                ),
                scope: $application->make(
                    NotionCircuitScope::class,
                ),
            ),
        );

        $this->app->bind(
            ProjectIntegrationSnapshotReader::class,
            EloquentProjectIntegrationSnapshotReader::class,
        );
    }

    /**
     * Configure Redis-backed queue backpressure for Notion publication jobs.
     */
    public function boot(): void
    {
        $jobsPerMinute = max(
            1,
            (int) config(
                'integration-resilience.notion.queue.jobs_per_minute',
                12,
            ),
        );

        RateLimiter::for(
            'notion-publication',
            static function (
                object $job,
            ) use ($jobsPerMinute): Limit {
                $organizationId = property_exists(
                    $job,
                    'organizationId',
                )
                    ? (string) $job->organizationId
                    : 'unknown';

                return Limit::perMinute($jobsPerMinute)
                    ->by(
                        'notion-publication:organization:'
                        .$organizationId,
                    );
            },
        );
    }
}
