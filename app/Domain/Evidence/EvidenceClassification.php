<?php

declare(strict_types=1);

namespace App\Domain\Evidence;

/**
 * Identifies the epistemic strength of one immutable evidence record.
 */
enum EvidenceClassification: string
{
    case Assumption = 'assumption';
    case Proposal = 'proposal';
    case SimulatedOutput = 'simulated_output';
    case ReportedEvidence = 'reported_evidence';
    case ObservedEvidence = 'observed_evidence';
    case VerifiedEvidence = 'verified_evidence';
    case RejectedEvidence = 'rejected_evidence';

    /**
     * Determine whether this record represents verified external truth.
     */
    public function isVerified(): bool
    {
        return $this === self::VerifiedEvidence;
    }
}
