<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

/**
 * Defines the evidence-backed truth classification of one ticket.
 *
 * Reported and observed state remain separate properties because neither one
 * alone automatically establishes verified implementation truth.
 */
enum TicketActualState: string
{
    case Unverified = 'unverified';
    case Observed = 'observed';
    case Verified = 'verified';
    case Rejected = 'rejected';

    /**
     * Determine whether authoritative verification has been completed.
     */
    public function isVerified(): bool
    {
        return $this === self::Verified;
    }
}
