<?php

declare(strict_types=1);

namespace App\Domain\QualityAssurance;

/**
 * Defines normalized impact, risk, and rollback-complexity levels.
 */
enum QaImpactLevel: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
