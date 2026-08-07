<?php

declare(strict_types=1);

namespace App\Domain\Development;

enum DevelopmentFailureClassification: string
{
    case None = 'none';
    case Validation = 'validation';
    case Provider = 'provider';
    case Timeout = 'timeout';
    case Cancellation = 'cancellation';
    case RetryExhausted = 'retry_exhausted';
}
