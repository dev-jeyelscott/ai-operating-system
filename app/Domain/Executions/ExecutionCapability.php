<?php

declare(strict_types=1);

namespace App\Domain\Executions;

use InvalidArgumentException;

/**
 * Defines provider-neutral execution capabilities.
 *
 * Historical capability values are normalized at application boundaries.
 * Existing persisted values are never destructively rewritten.
 */
enum ExecutionCapability: string
{
    case PlanningGenerate = 'planning.generate';

    case DevelopmentExecute = 'development.execute';

    case QualityAssuranceReview = 'quality_assurance.review';

    /**
     * Convert a canonical or supported historical value into its capability.
     */
    public static function fromStored(string $capability): self
    {
        return match ($capability) {
            self::PlanningGenerate->value,
            'planning.generate' => self::PlanningGenerate,

            self::DevelopmentExecute->value,
            'development',
            'development.execute' => self::DevelopmentExecute,

            self::QualityAssuranceReview->value,
            'quality_assurance.review' => self::QualityAssuranceReview,

            default => throw new InvalidArgumentException(sprintf(
                'Unsupported execution capability [%s].',
                $capability,
            )),
        };
    }

    /**
     * Return every persisted value that represents this capability.
     *
     * @return list<string>
     */
    public function storedValues(): array
    {
        return match ($this) {
            self::PlanningGenerate => [
                self::PlanningGenerate->value,
                'planning.generate',
            ],
            self::DevelopmentExecute => [
                self::DevelopmentExecute->value,
                'development',
                'development.execute',
            ],
            self::QualityAssuranceReview => [
                self::QualityAssuranceReview->value,
                'quality_assurance.review',
            ],
        };
    }

    /**
     * Determine whether a stored value represents this capability.
     */
    public function accepts(string $capability): bool
    {
        try {
            return self::fromStored($capability) === $this;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
