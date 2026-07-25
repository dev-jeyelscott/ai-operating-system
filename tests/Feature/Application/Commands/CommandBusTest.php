<?php

declare(strict_types=1);

namespace Tests\Feature\Application\Commands;

use App\Application\Shared\Commands\Command;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Commands\CommandResult;
use App\Application\Shared\Commands\CommandResultStatus;
use App\Application\Shared\Exceptions\ConflictException;
use App\Application\Shared\Exceptions\RetryableOperationException;
use App\Infrastructure\Bus\LaravelCommandBus;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifies command resolution and expected-failure normalization.
 */
final class CommandBusTest extends TestCase
{
    /**
     * The application container exposes the configured command-bus contract.
     */
    public function test_provider_registers_the_command_bus(): void
    {
        self::assertInstanceOf(
            LaravelCommandBus::class,
            $this->app->make(CommandBus::class),
        );
    }

    /**
     * The bus resolves a handler and returns its successful result unchanged.
     */
    public function test_it_dispatches_a_registered_command(): void
    {
        $result = $this->bus([
            SuccessfulCommand::class => SuccessfulCommandHandler::class,
        ])->dispatch(
            new SuccessfulCommand(workflowInstanceId: 42),
        );

        self::assertSame(CommandResultStatus::Succeeded, $result->status);

        self::assertSame([
            'workflow_instance_id' => 42,
        ], $result->data);
    }

    /**
     * Laravel validation exceptions become stable validation outcomes.
     */
    public function test_it_normalizes_validation_failures(): void
    {
        $result = $this->bus([
            ValidationCommand::class => ValidationCommandHandler::class,
        ])->dispatch(new ValidationCommand);

        self::assertSame(
            CommandResultStatus::ValidationFailed,
            $result->status,
        );

        self::assertSame([
            'fields' => [
                'state' => [
                    'The selected state is invalid.',
                ],
            ],
        ], $result->details);
    }

    /**
     * Application conflicts become stable conflict outcomes.
     */
    public function test_it_normalizes_conflicts(): void
    {
        $result = $this->bus([
            ConflictCommand::class => ConflictCommandHandler::class,
        ])->dispatch(new ConflictCommand);

        self::assertSame(CommandResultStatus::Conflict, $result->status);
        self::assertSame('state_conflict', $result->errorCode);

        self::assertSame(
            'The workflow transition is no longer allowed.',
            $result->message,
        );
    }

    /**
     * Transient application failures preserve their retry delay.
     */
    public function test_it_normalizes_retryable_failures(): void
    {
        $result = $this->bus([
            RetryableCommand::class => RetryableCommandHandler::class,
        ])->dispatch(new RetryableCommand);

        self::assertSame(
            CommandResultStatus::RetryableFailure,
            $result->status,
        );

        self::assertTrue($result->isRetryable());
        self::assertSame(15, $result->retryAfterSeconds);
    }

    /**
     * Missing handler mappings fail loudly as developer configuration errors.
     */
    public function test_it_rejects_an_unregistered_command(): void
    {
        $this->expectException(LogicException::class);

        $this->expectExceptionMessage(sprintf(
            'No command handler is registered for [%s].',
            SuccessfulCommand::class,
        ));

        $this->bus([])->dispatch(
            new SuccessfulCommand(workflowInstanceId: 42),
        );
    }

    /**
     * Handlers must honor the command-result contract.
     */
    public function test_it_rejects_an_invalid_handler_result(): void
    {
        $this->expectException(LogicException::class);

        $this->expectExceptionMessage(sprintf(
            'The command handler [%s] must return [%s].',
            InvalidResultCommandHandler::class,
            CommandResult::class,
        ));

        $this->bus([
            InvalidResultCommand::class => InvalidResultCommandHandler::class,
        ])->dispatch(new InvalidResultCommand);
    }

    /**
     * Unexpected exceptions remain visible to exception handling and CI.
     */
    public function test_it_does_not_hide_unexpected_failures(): void
    {
        $this->expectException(RuntimeException::class);

        $this->expectExceptionMessage(
            'Unexpected infrastructure failure.',
        );

        $this->bus([
            UnexpectedFailureCommand::class => UnexpectedFailureCommandHandler::class,
        ])->dispatch(new UnexpectedFailureCommand);
    }

    /**
     * Create a bus with the test-specific handler map.
     *
     * @param  array<class-string<Command>, class-string>  $handlers
     */
    private function bus(array $handlers): LaravelCommandBus
    {
        return new LaravelCommandBus(
            container: $this->app,
            handlers: $handlers,
        );
    }
}

/**
 * Carries the workflow instance identifier for the success-path test.
 */
final readonly class SuccessfulCommand implements Command
{
    /**
     * Create the immutable command.
     */
    public function __construct(
        public int $workflowInstanceId,
    ) {}
}

/**
 * Returns a stable success result for the supplied command.
 */
final class SuccessfulCommandHandler
{
    /**
     * Handle the successful test command.
     */
    public function handle(SuccessfulCommand $command): CommandResult
    {
        return CommandResult::succeeded([
            'workflow_instance_id' => $command->workflowInstanceId,
        ]);
    }
}

/**
 * Exercises Laravel validation-exception normalization.
 */
final readonly class ValidationCommand implements Command {}

/**
 * Produces a field-validation failure.
 */
final class ValidationCommandHandler
{
    /**
     * Reject the command with field validation errors.
     */
    public function handle(ValidationCommand $command): never
    {
        throw ValidationException::withMessages([
            'state' => [
                'The selected state is invalid.',
            ],
        ]);
    }
}

/**
 * Exercises application-conflict normalization.
 */
final readonly class ConflictCommand implements Command {}

/**
 * Produces a current-state conflict.
 */
final class ConflictCommandHandler
{
    /**
     * Reject the command because authoritative state changed.
     */
    public function handle(ConflictCommand $command): never
    {
        throw new ConflictException(
            'The workflow transition is no longer allowed.',
        );
    }
}

/**
 * Exercises retryable application-failure normalization.
 */
final readonly class RetryableCommand implements Command {}

/**
 * Produces a transient failure with a bounded retry delay.
 */
final class RetryableCommandHandler
{
    /**
     * Reject the command with a retryable infrastructure failure.
     */
    public function handle(RetryableCommand $command): never
    {
        throw new RetryableOperationException(
            message: 'The workflow store is temporarily unavailable.',
            retryAfterSeconds: 15,
        );
    }
}

/**
 * Exercises invalid handler-result detection.
 */
final readonly class InvalidResultCommand implements Command {}

/**
 * Deliberately violates the command-result contract.
 */
final class InvalidResultCommandHandler
{
    /**
     * Return an invalid value so contract enforcement can be tested.
     */
    public function handle(InvalidResultCommand $command): string
    {
        return 'invalid';
    }
}

/**
 * Exercises unexpected exception propagation.
 */
final readonly class UnexpectedFailureCommand implements Command {}

/**
 * Produces an unexpected failure that the bus must not normalize.
 */
final class UnexpectedFailureCommandHandler
{
    /**
     * Throw an unclassified failure that must remain visible.
     */
    public function handle(UnexpectedFailureCommand $command): never
    {
        throw new RuntimeException(
            'Unexpected infrastructure failure.',
        );
    }
}
