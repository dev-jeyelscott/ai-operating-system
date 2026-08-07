<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Data;

use App\Domain\QualityAssurance\ForQaTicketIneligibilityReason;

/**
 * Represents the complete deterministic result of one QA eligibility check.
 */
final readonly class ForQaTicketEligibilityResult
{
    /**
     * Create one eligibility result.
     *
     * An empty reason list means the ticket is eligible for Layer 3.
     *
     * @param  list<ForQaTicketIneligibilityReason>  $reasons
     * @param  list<string>  $missingArtifactTypes
     * @param  list<string>  $artifactTypesWithoutEvidence
     */
    public function __construct(
        public array $reasons,
        public array $missingArtifactTypes = [],
        public array $artifactTypesWithoutEvidence = [],
    ) {}

    /**
     * Determine whether every Layer 3 entry gate passed.
     */
    public function isEligible(): bool
    {
        return $this->reasons === [];
    }

    /**
     * Return machine-readable reason values for assertions and diagnostics.
     *
     * @return list<string>
     */
    public function reasonValues(): array
    {
        return array_map(
            static fn (
                ForQaTicketIneligibilityReason $reason,
            ): string => $reason->value,
            $this->reasons,
        );
    }
}
