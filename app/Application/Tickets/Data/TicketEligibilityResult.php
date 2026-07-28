<?php

declare(strict_types=1);

namespace App\Application\Tickets\Data;

use App\Domain\Tickets\TicketIneligibilityReason;

/**
 * Immutable result returned by the ticket eligibility evaluator.
 */
final readonly class TicketEligibilityResult
{
    /**
     * Store the ordered reasons that prevented execution.
     *
     * An empty reason list represents an eligible ticket.
     *
     * @param  list<TicketIneligibilityReason>  $reasons
     */
    public function __construct(
        public array $reasons,
    ) {}

    /**
     * Determine whether every required eligibility gate passed.
     */
    public function isEligible(): bool
    {
        return $this->reasons === [];
    }

    /**
     * Return stable scalar values for APIs, logs, and assertions.
     *
     * @return list<string>
     */
    public function reasonValues(): array
    {
        return array_map(
            static fn (
                TicketIneligibilityReason $reason,
            ): string => $reason->value,
            $this->reasons,
        );
    }
}
