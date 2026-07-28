<?php

declare(strict_types=1);

namespace App\Application\Tickets\Data;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable snapshot of the deterministic ranking facts for one eligible ticket.
 *
 * Eligibility must be evaluated before constructing the ranked candidate list.
 * AIOS-092 will construct these contexts from authoritative records inside the
 * atomic ticket-selection transaction.
 */
final readonly class TicketRankingContext
{
    /**
     * Supported ticket priority values in the approved planning contract.
     *
     * @var list<string>
     */
    private const PRIORITIES = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    /**
     * Create and validate one deterministic ticket-ranking candidate.
     *
     * roadmapOrder and explicitSequence use ascending order.
     * criticalPathRank uses descending order.
     * riskPolicyRank uses ascending order, where a lower value means the
     * project policy prefers that candidate.
     */
    public function __construct(
        public string $ticketId,
        public int $roadmapOrder,
        public bool $isCriticalPath,
        public int $criticalPathRank,
        public string $priority,
        public int $explicitSequence,
        public int $riskPolicyRank,
        public DateTimeImmutable $readyAt,
        public int $estimatedEffort,
    ) {
        if (
            $this->ticketId === ''
            || trim($this->ticketId) !== $this->ticketId
        ) {
            throw new InvalidArgumentException(
                'Ticket identifier must be a non-empty trimmed string.',
            );
        }

        if ($this->roadmapOrder < 1) {
            throw new InvalidArgumentException(
                'Approved roadmap order must be positive.',
            );
        }

        if ($this->criticalPathRank < 0) {
            throw new InvalidArgumentException(
                'Critical-path rank cannot be negative.',
            );
        }

        if (! in_array($this->priority, self::PRIORITIES, true)) {
            throw new InvalidArgumentException(
                'Ticket priority is invalid.',
            );
        }

        if ($this->explicitSequence < 1) {
            throw new InvalidArgumentException(
                'Explicit ticket sequence must be positive.',
            );
        }

        if ($this->riskPolicyRank < 0) {
            throw new InvalidArgumentException(
                'Risk-policy rank cannot be negative.',
            );
        }

        if (
            $this->estimatedEffort < 1
            || $this->estimatedEffort > 13
        ) {
            throw new InvalidArgumentException(
                'Estimated effort must be between 1 and 13.',
            );
        }
    }
}
