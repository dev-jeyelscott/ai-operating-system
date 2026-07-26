<?php

declare(strict_types=1);

namespace App\Application\Shared\Idempotency\Contracts;

use App\Application\Shared\Commands\CommandResult;
use Closure;

/**
 * Coordinates durable execution and replay for idempotent commands.
 */
interface IdempotencyKeyService
{
    /**
     * Execute an operation once or replay its previously persisted result.
     *
     * @param  Closure(): CommandResult  $operation
     */
    public function execute(
        IdempotentCommand $command,
        Closure $operation,
    ): CommandResult;
}
