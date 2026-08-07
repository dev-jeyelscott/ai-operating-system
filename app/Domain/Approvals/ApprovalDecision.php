<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

/**
 * Defines the decisions an authorized human may make.
 */
enum ApprovalDecision: string
{
    case Approve = 'approve';
    case Reject = 'reject';

    /**
     * Resolve the terminal approval status produced by this decision.
     */
    public function status(): ApprovalStatus
    {
        return match ($this) {
            self::Approve => ApprovalStatus::Approved,
            self::Reject => ApprovalStatus::Rejected,
        };
    }
}
