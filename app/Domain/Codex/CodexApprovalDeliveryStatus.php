<?php

declare(strict_types=1);

namespace App\Domain\Codex;

/**
 * Tracks delivery of the application-owned decision back to Codex.
 *
 * The human decision and provider delivery are separate facts because a worker
 * may fail after the decision commits but before the JSON-RPC response is sent.
 */
enum CodexApprovalDeliveryStatus: string
{
    case AwaitingDecision = 'awaiting_decision';
    case Pending = 'pending';
    case Sending = 'sending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Stale = 'stale';
    case AlreadyResolved = 'already_resolved';

    /**
     * Determine whether no provider delivery should be attempted again.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered,
            self::Stale,
            self::AlreadyResolved => true,

            default => false,
        };
    }
}
