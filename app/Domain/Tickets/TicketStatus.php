<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

/**
 * Defines the authoritative internal workflow status of one roadmap ticket.
 *
 * External providers and Notion may report a different state, but those claims
 * must not directly replace this authoritative internal status.
 */
enum TicketStatus: string
{
    case Backlog = 'backlog';
    case Ready = 'ready';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case ForQa = 'for_qa';
    case ChangesRequested = 'changes_requested';
    case ApprovedForMerge = 'approved_for_merge';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /**
     * Determine whether the ticket has reached a terminal workflow status.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done,
            self::Cancelled => true,
            default => false,
        };
    }
}
