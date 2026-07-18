<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

/**
 * Defines the default model reasoning level requested by project policy.
 */
enum ReasoningLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
