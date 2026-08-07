<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Defines the supported server-resolved notification destinations.
 */
enum NotificationActionType: string
{
    case Approval = 'approval';
    case Execution = 'execution';
    case Blocker = 'blocker';
    case Decision = 'decision';
}
