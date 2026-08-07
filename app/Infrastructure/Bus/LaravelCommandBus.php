<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use App\Application\Shared\Commands\Command;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Commands\CommandResult;
use App\Application\Shared\Exceptions\ConflictException;
use App\Application\Shared\Exceptions\RetryableOperationException;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Resolves synchronous command handlers through Laravel's service container.
 */
final readonly class LaravelCommandBus implements CommandBus
{
    /**
     * Create the bus with an immutable command-to-handler map.
     *
     * @param  array<class-string<Command>, class-string>  $handlers
     */
    public function __construct(
        private Container $container,
        private array $handlers,
    ) {}

    /**
     * Dispatch one command and normalize expected application failures.
     *
     * Unexpected exceptions are deliberately not caught. Programming errors,
     * database faults, and unclassified infrastructure failures must remain
     * visible to Laravel's exception handler, logs, monitoring, and CI.
     */
    public function dispatch(Command $command): CommandResult
    {
        $handlerClass = $this->handlers[$command::class] ?? null;

        if ($handlerClass === null) {
            throw new LogicException(sprintf(
                'No command handler is registered for [%s].',
                $command::class,
            ));
        }

        $handler = $this->container->make($handlerClass);
        $callable = [$handler, 'handle'];

        if (! is_callable($callable)) {
            throw new LogicException(sprintf(
                'The command handler [%s] must expose a public handle method.',
                $handlerClass,
            ));
        }

        $handle = Closure::fromCallable($callable);

        try {
            $result = $handle($command);
        } catch (ValidationException $exception) {
            return CommandResult::validationFailed(
                fieldErrors: $exception->errors(),
            );
        } catch (InvalidArgumentException $exception) {
            return CommandResult::validationFailed(
                message: $exception->getMessage(),
            );
        } catch (ConflictException $exception) {
            return CommandResult::conflict(
                message: $exception->getMessage(),
            );
        } catch (RetryableOperationException $exception) {
            return CommandResult::retryableFailure(
                message: $exception->getMessage(),
                retryAfterSeconds: $exception->retryAfterSeconds,
            );
        }

        if (! $result instanceof CommandResult) {
            throw new LogicException(sprintf(
                'The command handler [%s] must return [%s].',
                $handlerClass,
                CommandResult::class,
            ));
        }

        return $result;
    }
}
