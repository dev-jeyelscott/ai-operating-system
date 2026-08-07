<?php

declare(strict_types=1);

namespace App\Application\Tickets\Data;

use App\Models\RoadmapTask;
use App\Models\TicketExecutionLease;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Immutable scalar result for one atomic ticket-selection attempt.
 */
final readonly class TicketSelectionResult
{
    /**
     * Create a consistent selected or no-work result.
     */
    private function __construct(
        public ?int $roadmapTaskId,
        public ?string $ticketId,
        public ?string $leaseId,
        public ?CarbonImmutable $leaseExpiresAt,
    ) {
        $selectedValues = [
            $this->roadmapTaskId,
            $this->ticketId,
            $this->leaseId,
            $this->leaseExpiresAt,
        ];

        $presentCount = count(array_filter(
            $selectedValues,
            static fn (mixed $value): bool => $value !== null,
        ));

        if ($presentCount !== 0 && $presentCount !== count($selectedValues)) {
            throw new LogicException(
                'Ticket selection result must be fully selected or empty.',
            );
        }
    }

    /**
     * Build the result returned after a lease is acquired or replayed.
     */
    public static function selected(
        RoadmapTask $ticket,
        TicketExecutionLease $lease,
    ): self {
        if (
            $ticket->id !== $lease->roadmap_task_id
            || $ticket->roadmap->project_id !== $lease->project_id
        ) {
            throw new LogicException(
                'Selected ticket and lease ownership are inconsistent.',
            );
        }

        return new self(
            roadmapTaskId: $ticket->id,
            ticketId: $ticket->stable_id,
            leaseId: $lease->id,
            leaseExpiresAt: $lease->expires_at,
        );
    }

    /**
     * Build the normal result returned when no ticket is workable.
     */
    public static function noWorkableTicket(): self
    {
        return new self(
            roadmapTaskId: null,
            ticketId: null,
            leaseId: null,
            leaseExpiresAt: null,
        );
    }

    /**
     * Determine whether this attempt selected a ticket.
     */
    public function isSelected(): bool
    {
        return $this->leaseId !== null;
    }
}
