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
        foreach (self::cases() as $case) {
            if ($case->accepts($capability)) {
                return $case;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Unsupported execution capability [%s].',
            $capability,
        ));
    }

    /**
     * Determine whether this capability accepts the supplied persisted value.
     */
    public function accepts(string $capability): bool
    {
        return in_array(
            $capability,
            $this->storedValues(),
            true,
        );
    }

    /**
     * Return every canonical and historical value supported by this capability.
     *
     * @return list<string>
     */
    public function storedValues(): array
    {
        return match ($this) {
            self::PlanningGenerate => [
                self::PlanningGenerate->value,
                'planning.roadmap',
            ],

            self::DevelopmentExecute => [
                self::DevelopmentExecute->value,
                'development',
                'development.simulation',
            ],

            self::QualityAssuranceReview => [
                self::QualityAssuranceReview->value,
                'quality_assurance.simulation',
            ],
        };
    }
}
