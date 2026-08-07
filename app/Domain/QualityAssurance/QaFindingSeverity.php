<?php

declare(strict_types=1);

namespace App\Domain\QualityAssurance;

/**
 * Defines the severity assigned to one unresolved QA finding.
 */
enum QaFindingSeverity: string
{
    case Info = 'info';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
