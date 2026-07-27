<?php

declare(strict_types=1);

namespace App\Application\Shared\Commands;

/**
 * Defines the stable outcome categories returned by application commands.
 */
enum CommandResultStatus: string
{
    case Succeeded = 'succeeded';
    case ValidationFailed = 'validation_failed';
    case Conflict = 'conflict';
    case RetryableFailure = 'retryable_failure';
}
