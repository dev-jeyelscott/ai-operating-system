<?php

declare(strict_types=1);

namespace App\Domain\Codex;

/**
 * Defines provider-neutral normalized activity persisted from Codex protocol
 * messages.
 */
enum ProviderEventType: string
{
    case ThreadStarted = 'thread_started';

    case StatusChanged = 'status_changed';

    case TurnStarted = 'turn_started';

    case TurnCompleted = 'turn_completed';

    case TurnFailed = 'turn_failed';

    case ItemStarted = 'item_started';

    case ItemCompleted = 'item_completed';

    case ApprovalRequested = 'approval_requested';

    case ApprovalResolved = 'approval_resolved';

    case CommandRequested = 'command_requested';

    case CommandCompleted = 'command_completed';

    case OutputDelta = 'output_delta';

    case DiffUpdated = 'diff_updated';

    case UsageUpdated = 'usage_updated';

    case Warning = 'warning';
}
