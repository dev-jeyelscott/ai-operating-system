<?php

declare(strict_types=1);

use App\Domain\Tickets\TicketActualState;
use App\Domain\Tickets\TicketStatus;

test(
    'ticket status exposes the canonical internal workflow values',
    function (): void {
        $values = array_map(
            static fn (TicketStatus $status): string => $status->value,
            TicketStatus::cases(),
        );

        expect($values)->toBe([
            'backlog',
            'ready',
            'in_progress',
            'blocked',
            'for_qa',
            'changes_requested',
            'approved_for_merge',
            'done',
            'cancelled',
        ]);
    },
);

test(
    'only done and cancelled are terminal ticket statuses',
    function (): void {
        expect(TicketStatus::Backlog->isTerminal())
            ->toBeFalse()
            ->and(TicketStatus::Ready->isTerminal())
            ->toBeFalse()
            ->and(TicketStatus::InProgress->isTerminal())
            ->toBeFalse()
            ->and(TicketStatus::Blocked->isTerminal())
            ->toBeFalse()
            ->and(TicketStatus::ForQa->isTerminal())
            ->toBeFalse()
            ->and(TicketStatus::ChangesRequested->isTerminal())
            ->toBeFalse()
            ->and(TicketStatus::ApprovedForMerge->isTerminal())
            ->toBeFalse()
            ->and(TicketStatus::Done->isTerminal())
            ->toBeTrue()
            ->and(TicketStatus::Cancelled->isTerminal())
            ->toBeTrue();
    },
);

test(
    'actual state exposes the approved evidence truth values',
    function (): void {
        $values = array_map(
            static fn (
                TicketActualState $state,
            ): string => $state->value,
            TicketActualState::cases(),
        );

        expect($values)->toBe([
            'unverified',
            'observed',
            'verified',
            'rejected',
        ]);
    },
);

test(
    'only verified actual state establishes authoritative verification',
    function (): void {
        expect(TicketActualState::Unverified->isVerified())
            ->toBeFalse()
            ->and(TicketActualState::Observed->isVerified())
            ->toBeFalse()
            ->and(TicketActualState::Rejected->isVerified())
            ->toBeFalse()
            ->and(TicketActualState::Verified->isVerified())
            ->toBeTrue();
    },
);
