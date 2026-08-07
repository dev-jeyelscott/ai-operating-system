<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

/**
 * Identifies the authority that supplied a reconciled external-state claim.
 */
enum TicketExternalStateSource: string
{
    case Notion = 'notion';
    case ExecutionProvider = 'execution_provider';
    case DeterministicObserver = 'deterministic_observer';
}
