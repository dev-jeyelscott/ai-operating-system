<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use App\Application\Shared\Commands\Command;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Commands\CommandResult;
use App\Application\Shared\Idempotency\Contracts\IdempotencyKeyService;
use App\Application\Shared\Idempotency\Contracts\IdempotentCommand;

/**
 * Adds transparent idempotency to commands that opt into the contract.
 */
final readonly class IdempotentCommandBus implements CommandBus
{
    /**
     * Wrap the existing Laravel command bus with durable replay protection.
     */
    public function __construct(
        private CommandBus $inner,
        private IdempotencyKeyService $idempotencyKeys,
    ) {}

    /**
     * Dispatch normal commands directly and idempotent commands through the
     * durable key service.
     */
    public function dispatch(Command $command): CommandResult
    {
        if (! $command instanceof IdempotentCommand) {
            return $this->inner->dispatch($command);
        }

        return $this->idempotencyKeys->execute(
            command: $command,
            operation: fn (): CommandResult => $this->inner->dispatch(
                $command,
            ),
        );
    }
}
