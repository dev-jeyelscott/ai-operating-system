<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

enum TicketLeaseReleaseReason: string
{
    case Completion = 'completion';
    case TerminalFailure = 'terminal_failure';
    case Cancellation = 'cancellation';
    case ManualRecovery = 'manual_recovery';
}
