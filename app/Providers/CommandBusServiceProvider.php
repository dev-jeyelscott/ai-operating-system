<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Shared\Commands\Command;
use App\Application\Shared\Commands\CommandBus;
use App\Infrastructure\Bus\LaravelCommandBus;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * Registers the synchronous application command bus and handler map.
 */
final class CommandBusServiceProvider extends ServiceProvider
{
    /**
     * Register the command bus as one stateless application singleton.
     */
    public function register(): void
    {
        $this->app->singleton(
            CommandBus::class,
            function (Application $app): CommandBus {
                $handlers = config('command-bus.handlers', []);

                if (! is_array($handlers)) {
                    throw new LogicException(
                        'The command bus handler configuration must be an array.',
                    );
                }

                return new LaravelCommandBus(
                    container: $app,
                    handlers: $this->normalizeHandlers($handlers),
                );
            },
        );
    }

    /**
     * Validate and normalize the configured command-to-handler map.
     *
     * @param  array<array-key, mixed>  $handlers
     * @return array<class-string<Command>, class-string>
     */
    private function normalizeHandlers(array $handlers): array
    {
        $normalized = [];

        foreach ($handlers as $commandClass => $handlerClass) {
            if (
                ! is_string($commandClass)
                || ! is_a($commandClass, Command::class, true)
                || ! is_string($handlerClass)
                || $handlerClass === ''
                || ! class_exists($handlerClass)
            ) {
                throw new LogicException(
                    'Each command bus mapping must contain a command class and an existing handler class.',
                );
            }

            $normalized[$commandClass] = $handlerClass;
        }

        return $normalized;
    }
}
