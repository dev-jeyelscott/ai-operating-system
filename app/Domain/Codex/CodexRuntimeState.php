<?php

declare(strict_types=1);

namespace App\Domain\Codex;

/**
 * Describes what the local runtime can conclusively establish about a process.
 */
enum CodexRuntimeState: string
{
    case Running = 'running';
    case Exited = 'exited';

    /**
     * The process belongs to another host or cannot be inspected safely.
     */
    case Unreachable = 'unreachable';

    /**
     * The PID exists but no longer identifies the originally recorded process.
     */
    case IdentityMismatch = 'identity_mismatch';

    /**
     * Determine whether automatic recovery has conclusively proven termination.
     */
    public function isConclusiveExit(): bool
    {
        return $this === self::Exited;
    }
}
