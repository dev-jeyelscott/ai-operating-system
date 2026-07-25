<?php

declare(strict_types=1);

namespace App\Application\Shared\Commands;

/**
 * Dispatches synchronous application commands to their registered handlers.
 */
interface CommandBus
{
    /**
     * Dispatch the command and return one stable application outcome.
     */
    public function dispatch(Command $command): CommandResult;
}
