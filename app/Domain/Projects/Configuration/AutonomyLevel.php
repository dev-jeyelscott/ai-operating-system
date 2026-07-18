<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

/**
 * Defines how much authority automated execution receives for a project.
 */
enum AutonomyLevel: string
{
    case Advisory = 'advisory';
    case ApprovalRequired = 'approval_required';
    case PolicyControlled = 'policy_controlled';
}
