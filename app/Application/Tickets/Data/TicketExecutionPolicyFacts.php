<?php

declare(strict_types=1);

namespace App\Application\Tickets\Data;

use App\Models\RoadmapTask;
use InvalidArgumentException;

/**
 * Immutable application-derived execution policy used by ticket eligibility.
 */
final readonly class TicketExecutionPolicyFacts
{
    /**
     * @param  array<int, true>  $approvedRoadmapTaskIds
     * @param  array<string, true>  $approvedStableTicketIds
     */
    public function __construct(
        public bool $providerSupportsExecution,
        public bool $budgetPermitsExecution,
        public int $attemptCount,
        public int $retryLimit,
        private array $approvedRoadmapTaskIds,
        private array $approvedStableTicketIds,
    ) {
        if ($this->attemptCount < 0 || $this->retryLimit < 0) {
            throw new InvalidArgumentException(
                'Execution policy counters cannot be negative.',
            );
        }
    }

    public function approvalGrantedFor(RoadmapTask $ticket): bool
    {
        return isset($this->approvedRoadmapTaskIds[$ticket->id])
            || isset($this->approvedStableTicketIds[$ticket->stable_id]);
    }
}
