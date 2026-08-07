<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Codex\Contracts\CodexProcessGateway;
use App\Infrastructure\Codex\Process\CodexAppServerSettings;
use App\Infrastructure\Codex\Process\SymfonyCodexProcessGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Codex process infrastructure without registering Codex as an
 * execution provider.
 */
final class CodexServiceProvider extends ServiceProvider
{
    /**
     * Bind the transport contract and lazily validated App Server settings.
     */
    public function register(): void
    {
        $this->app->singleton(
            CodexAppServerSettings::class,
            static fn (): CodexAppServerSettings => CodexAppServerSettings::fromConfiguration(),
        );

        $this->app->bind(
            CodexProcessGateway::class,
            SymfonyCodexProcessGateway::class,
        );
    }
}
