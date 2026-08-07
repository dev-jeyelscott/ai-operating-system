<?php

declare(strict_types=1);

namespace App\Domain\Idempotency;

/**
 * Represents the durable lifecycle of one idempotency-key record.
 */
enum IdempotencyKeyStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
}
