<?php

declare(strict_types=1);

namespace App\Domain\QualityAssurance;

/**
 * Defines the authorized human dispositions for one simulated merge assessment.
 */
enum MergeDecisionAction: string
{
    case Approve = 'approve';

    case RequestChanges = 'request_changes';

    case Escalate = 'escalate';

    case Defer = 'defer';

    /**
     * Determine whether this action permanently closes the assessment decision.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Approve,
            self::RequestChanges => true,

            self::Escalate,
            self::Defer => false,
        };
    }

    /**
     * Determine whether an explanatory human reason is mandatory.
     */
    public function requiresReason(): bool
    {
        return match ($this) {
            self::RequestChanges,
            self::Escalate => true,

            self::Approve,
            self::Defer => false,
        };
    }
}
