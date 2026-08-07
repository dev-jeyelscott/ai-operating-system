<?php

declare(strict_types=1);

namespace App\Domain\Codex;

/**
 * Defines the bounded lifecycle phases of one Codex provider process.
 */
enum CodexExecutionPhase: string
{
    case Startup = 'startup';
    case Idle = 'idle';
    case Turn = 'turn';
    case ApprovalWait = 'approval_wait';
    case Validation = 'validation';
    case Shutdown = 'shutdown';
}
