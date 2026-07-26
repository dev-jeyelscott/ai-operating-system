<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

/**
 * Identifies the consequential operation awaiting human approval.
 */
enum ApprovalType: string
{
    case Roadmap = 'roadmap';
    case WorkflowTransition = 'workflow_transition';
    case Execution = 'execution';
    case Merge = 'merge';
    case Recovery = 'recovery';
}
