<?php

declare(strict_types=1);

namespace App\Domain\Executions;

use InvalidArgumentException;

enum ExecutionCapability: string
{
    case PlanningGenerate = 'planning.generate';
    case DevelopmentExecute = 'development.execute';
    case QualityAssuranceReview = 'quality_assurance.review';

    /**
     * Normalize a canonical or historical persisted execution capability.
     *
     * Historical capability names are accepted only at the persistence
     * compatibility boundary and are immediately converted to their
     * provider-neutral canonical capability.
     */
    public static function fromStored(string $capability): self
    {
        return match ($capability) {
            self::PlanningGenerate->value,
            'planning.roadmap' => self::PlanningGenerate,

            self::DevelopmentExecute->value,
            'development',
            'development.simulation' => self::DevelopmentExecute,

            self::QualityAssuranceReview->value,
            'quality_assurance.simulation' => self::QualityAssuranceReview,

            default => throw new InvalidArgumentException(sprintf(
                'Unsupported execution capability [%s].',
                $capability,
            )),
        };
    }
}
