<?php

declare(strict_types=1);

namespace App\Application\Tickets;

use App\Application\Tickets\Data\TicketRankingContext;
use InvalidArgumentException;
use LogicException;

/**
 * Deterministically ranks tickets that have already passed eligibility checks.
 *
 * This service performs no database queries, state changes, or lease
 * acquisition. Atomic selection and lease creation belong to AIOS-092.
 */
final class TicketRanker
{
    /**
     * Priority weights ordered from least to most important.
     *
     * @var array<string, int>
     */
    private const PRIORITY_RANKS = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    /**
     * Rank eligible tickets using the approved precedence rules.
     *
     * The final ticket identifier comparison guarantees the same result even
     * when otherwise identical candidates enter in a different input order.
     *
     * @param  array<array-key, mixed>  $tickets
     * @return list<TicketRankingContext>
     */
    public function rank(array $tickets): array
    {
        if (! array_is_list($tickets)) {
            throw new InvalidArgumentException(
                'Ticket ranking candidates must be a list.',
            );
        }

        $rankedTickets = [];

        foreach ($tickets as $ticket) {
            if (! $ticket instanceof TicketRankingContext) {
                throw new InvalidArgumentException(
                    'Every ranking candidate must be a TicketRankingContext.',
                );
            }

            $rankedTickets[] = $ticket;
        }

        usort(
            $rankedTickets,
            fn (
                TicketRankingContext $left,
                TicketRankingContext $right,
            ): int => $this->compare($left, $right),
        );

        return $rankedTickets;
    }

    /**
     * Compare two candidates according to the approved ranking precedence.
     */
    private function compare(
        TicketRankingContext $left,
        TicketRankingContext $right,
    ): int {
        $roadmapOrder =
            $left->roadmapOrder <=> $right->roadmapOrder;

        if ($roadmapOrder !== 0) {
            return $roadmapOrder;
        }

        $criticalPathMembership =
            (int) $right->isCriticalPath
            <=> (int) $left->isCriticalPath;

        if ($criticalPathMembership !== 0) {
            return $criticalPathMembership;
        }

        $criticalPathRank =
            $right->criticalPathRank
            <=> $left->criticalPathRank;

        if ($criticalPathRank !== 0) {
            return $criticalPathRank;
        }

        $priority =
            $this->priorityRank($right->priority)
            <=> $this->priorityRank($left->priority);

        if ($priority !== 0) {
            return $priority;
        }

        $explicitSequence =
            $left->explicitSequence
            <=> $right->explicitSequence;

        if ($explicitSequence !== 0) {
            return $explicitSequence;
        }

        $riskPolicy =
            $left->riskPolicyRank
            <=> $right->riskPolicyRank;

        if ($riskPolicy !== 0) {
            return $riskPolicy;
        }

        $readyAt = $left->readyAt <=> $right->readyAt;

        if ($readyAt !== 0) {
            return $readyAt;
        }

        $estimatedEffort =
            $left->estimatedEffort
            <=> $right->estimatedEffort;

        if ($estimatedEffort !== 0) {
            return $estimatedEffort;
        }

        return strcmp($left->ticketId, $right->ticketId);
    }

    /**
     * Resolve a validated priority value to its deterministic numeric rank.
     */
    private function priorityRank(string $priority): int
    {
        $rank = self::PRIORITY_RANKS[$priority] ?? null;

        if ($rank === null) {
            throw new LogicException(
                'A validated ticket priority has no ranking weight.',
            );
        }

        return $rank;
    }
}
