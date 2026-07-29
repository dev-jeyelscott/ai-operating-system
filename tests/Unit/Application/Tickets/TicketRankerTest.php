<?php

declare(strict_types=1);

use App\Application\Tickets\Data\TicketRankingContext;
use App\Application\Tickets\TicketRanker;

/**
 * Create a valid AIOS-091 ranking candidate with optional overrides.
 */
function aios091RankingContext(
    string $ticketId,
    int $roadmapOrder = 1,
    bool $isCriticalPath = false,
    int $criticalPathRank = 0,
    string $priority = 'medium',
    int $explicitSequence = 1,
    int $riskPolicyRank = 0,
    string $readyAt = '2026-07-01T00:00:00+00:00',
    int $estimatedEffort = 5,
): TicketRankingContext {
    return new TicketRankingContext(
        ticketId: $ticketId,
        roadmapOrder: $roadmapOrder,
        isCriticalPath: $isCriticalPath,
        criticalPathRank: $criticalPathRank,
        priority: $priority,
        explicitSequence: $explicitSequence,
        riskPolicyRank: $riskPolicyRank,
        readyAt: new DateTimeImmutable($readyAt),
        estimatedEffort: $estimatedEffort,
    );
}

/**
 * Return ticket identifiers from ranked candidate contexts.
 *
 * @param  list<TicketRankingContext>  $tickets
 * @return list<string>
 */
function aios091TicketIds(array $tickets): array
{
    return array_map(
        static fn (TicketRankingContext $ticket): string => $ticket->ticketId,
        $tickets,
    );
}

test(
    'approved roadmap order has the highest ranking precedence',
    function (): void {
        $ranked = (new TicketRanker)->rank([
            aios091RankingContext(
                ticketId: 'AIOS-SECOND',
                roadmapOrder: 2,
                isCriticalPath: true,
                criticalPathRank: 100,
                priority: 'critical',
                explicitSequence: 1,
                riskPolicyRank: 0,
                readyAt: '2026-01-01T00:00:00+00:00',
                estimatedEffort: 1,
            ),
            aios091RankingContext(
                ticketId: 'AIOS-FIRST',
                roadmapOrder: 1,
                isCriticalPath: false,
                criticalPathRank: 0,
                priority: 'low',
                explicitSequence: 99,
                riskPolicyRank: 99,
                readyAt: '2026-07-01T00:00:00+00:00',
                estimatedEffort: 13,
            ),
        ]);

        expect(aios091TicketIds($ranked))
            ->toBe(['AIOS-FIRST', 'AIOS-SECOND']);
    },
);

test(
    'critical path membership wins after roadmap order',
    function (): void {
        $ranked = (new TicketRanker)->rank([
            aios091RankingContext(
                ticketId: 'AIOS-NON-CRITICAL',
                priority: 'critical',
            ),
            aios091RankingContext(
                ticketId: 'AIOS-CRITICAL',
                isCriticalPath: true,
                criticalPathRank: 8,
                priority: 'low',
            ),
        ]);

        expect(aios091TicketIds($ranked))
            ->toBe(['AIOS-CRITICAL', 'AIOS-NON-CRITICAL']);
    },
);

test(
    'higher critical path rank wins between critical path tickets',
    function (): void {
        $ranked = (new TicketRanker)->rank([
            aios091RankingContext(
                ticketId: 'AIOS-SHORTER-PATH',
                isCriticalPath: true,
                criticalPathRank: 5,
            ),
            aios091RankingContext(
                ticketId: 'AIOS-LONGER-PATH',
                isCriticalPath: true,
                criticalPathRank: 13,
            ),
        ]);

        expect(aios091TicketIds($ranked))
            ->toBe(['AIOS-LONGER-PATH', 'AIOS-SHORTER-PATH']);
    },
);

test(
    'higher priority wins after roadmap and critical path criteria',
    function (): void {
        $ranked = (new TicketRanker)->rank([
            aios091RankingContext(
                ticketId: 'AIOS-LOW',
                priority: 'low',
            ),
            aios091RankingContext(
                ticketId: 'AIOS-CRITICAL',
                priority: 'critical',
            ),
            aios091RankingContext(
                ticketId: 'AIOS-HIGH',
                priority: 'high',
            ),
            aios091RankingContext(
                ticketId: 'AIOS-MEDIUM',
                priority: 'medium',
            ),
        ]);

        expect(aios091TicketIds($ranked))
            ->toBe([
                'AIOS-CRITICAL',
                'AIOS-HIGH',
                'AIOS-MEDIUM',
                'AIOS-LOW',
            ]);
    },
);

test(
    'lower explicit sequence wins after priority',
    function (): void {
        $ranked = (new TicketRanker)->rank([
            aios091RankingContext(
                ticketId: 'AIOS-SEQUENCE-TWO',
                explicitSequence: 2,
            ),
            aios091RankingContext(
                ticketId: 'AIOS-SEQUENCE-ONE',
                explicitSequence: 1,
            ),
        ]);

        expect(aios091TicketIds($ranked))
            ->toBe([
                'AIOS-SEQUENCE-ONE',
                'AIOS-SEQUENCE-TWO',
            ]);
    },
);

test(
    'lower risk policy rank wins after explicit sequence',
    function (): void {
        $ranked = (new TicketRanker)->rank([
            aios091RankingContext(
                ticketId: 'AIOS-RISK-DEFERRED',
                riskPolicyRank: 10,
            ),
            aios091RankingContext(
                ticketId: 'AIOS-RISK-PREFERRED',
                riskPolicyRank: 1,
            ),
        ]);

        expect(aios091TicketIds($ranked))
            ->toBe([
                'AIOS-RISK-PREFERRED',
                'AIOS-RISK-DEFERRED',
            ]);
    },
);

test(
    'oldest ready timestamp wins after risk policy',
    function (): void {
        $ranked = (new TicketRanker)->rank([
            aios091RankingContext(
                ticketId: 'AIOS-READY-LATER',
                readyAt: '2026-07-10T00:00:00+00:00',
            ),
            aios091RankingContext(
                ticketId: 'AIOS-READY-EARLIER',
                readyAt: '2026-07-01T00:00:00+00:00',
            ),
        ]);

        expect(aios091TicketIds($ranked))
            ->toBe([
                'AIOS-READY-EARLIER',
                'AIOS-READY-LATER',
            ]);
    },
);

test(
    'lowest estimated effort is the final business tie breaker',
    function (): void {
        $ranked = (new TicketRanker)->rank([
            aios091RankingContext(
                ticketId: 'AIOS-EFFORT-EIGHT',
                estimatedEffort: 8,
            ),
            aios091RankingContext(
                ticketId: 'AIOS-EFFORT-THREE',
                estimatedEffort: 3,
            ),
        ]);

        expect(aios091TicketIds($ranked))
            ->toBe([
                'AIOS-EFFORT-THREE',
                'AIOS-EFFORT-EIGHT',
            ]);
    },
);

test(
    'ticket identifier guarantees input order independent results',
    function (): void {
        $ticketA = aios091RankingContext('AIOS-A');
        $ticketB = aios091RankingContext('AIOS-B');

        $firstOrdering = (new TicketRanker)->rank([
            $ticketB,
            $ticketA,
        ]);

        $secondOrdering = (new TicketRanker)->rank([
            $ticketA,
            $ticketB,
        ]);

        expect(aios091TicketIds($firstOrdering))
            ->toBe(['AIOS-A', 'AIOS-B'])
            ->and(aios091TicketIds($secondOrdering))
            ->toBe(['AIOS-A', 'AIOS-B']);
    },
);

test(
    'ranker rejects invalid candidate collections',
    function (): void {
        $candidate = aios091RankingContext('AIOS-VALID');

        expect(
            fn (): array => (new TicketRanker)->rank([
                'candidate' => $candidate,
            ]),
        )->toThrow(
            InvalidArgumentException::class,
            'Ticket ranking candidates must be a list.',
        );

        expect(
            fn (): array => (new TicketRanker)->rank([
                $candidate,
                'invalid candidate',
            ]),
        )->toThrow(
            InvalidArgumentException::class,
            'Every ranking candidate must be a TicketRankingContext.',
        );
    },
);

test(
    'ranking context rejects invalid ranking facts',
    function (): void {
        expect(
            fn (): TicketRankingContext => aios091RankingContext(''),
        )->toThrow(
            InvalidArgumentException::class,
            'Ticket identifier must be a non-empty trimmed string.',
        );

        expect(
            fn (): TicketRankingContext => aios091RankingContext(
                ticketId: 'AIOS-INVALID',
                roadmapOrder: 0,
            ),
        )->toThrow(
            InvalidArgumentException::class,
            'Approved roadmap order must be positive.',
        );

        expect(
            fn (): TicketRankingContext => aios091RankingContext(
                ticketId: 'AIOS-INVALID',
                criticalPathRank: -1,
            ),
        )->toThrow(
            InvalidArgumentException::class,
            'Critical-path rank cannot be negative.',
        );

        expect(
            fn (): TicketRankingContext => aios091RankingContext(
                ticketId: 'AIOS-INVALID',
                priority: 'urgent',
            ),
        )->toThrow(
            InvalidArgumentException::class,
            'Ticket priority is invalid.',
        );

        expect(
            fn (): TicketRankingContext => aios091RankingContext(
                ticketId: 'AIOS-INVALID',
                explicitSequence: 0,
            ),
        )->toThrow(
            InvalidArgumentException::class,
            'Explicit ticket sequence must be positive.',
        );

        expect(
            fn (): TicketRankingContext => aios091RankingContext(
                ticketId: 'AIOS-INVALID',
                riskPolicyRank: -1,
            ),
        )->toThrow(
            InvalidArgumentException::class,
            'Risk-policy rank cannot be negative.',
        );

        expect(
            fn (): TicketRankingContext => aios091RankingContext(
                ticketId: 'AIOS-INVALID',
                estimatedEffort: 14,
            ),
        )->toThrow(
            InvalidArgumentException::class,
            'Estimated effort must be between 1 and 13.',
        );
    },
);
