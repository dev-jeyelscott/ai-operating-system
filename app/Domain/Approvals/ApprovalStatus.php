<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

/**
 * Represents the authoritative lifecycle state of an approval.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    /**
     * Determine whether no further decision may be applied.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
