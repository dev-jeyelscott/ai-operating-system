<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Application\Integrations\Contracts\NotionConnectionGateway;
use App\Infrastructure\Integrations\LaravelIntegrationCredentialCipher;
use App\Infrastructure\Integrations\Notion\HttpNotionConnectionGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Integrations module infrastructure adapters.
 */
final class IntegrationsServiceProvider extends ServiceProvider
{
    /**
     * Bind integration application contracts to infrastructure implementations.
     */
    public function register(): void
    {
        $this->app->bind(
            IntegrationCredentialCipher::class,
            LaravelIntegrationCredentialCipher::class,
        );

        $this->app->bind(
            NotionConnectionGateway::class,
            HttpNotionConnectionGateway::class,
        );
    }
}
