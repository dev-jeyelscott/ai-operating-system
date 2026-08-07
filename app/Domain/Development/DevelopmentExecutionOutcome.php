<?php

declare(strict_types=1);

namespace App\Domain\Development;

enum DevelopmentExecutionOutcome: string
{
    case Succeeded = 'succeeded';
    case ValidationFailed = 'validation_failed';
    case ProviderFailed = 'provider_failed';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';
}
