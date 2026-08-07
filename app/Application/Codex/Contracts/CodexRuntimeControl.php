<?php

declare(strict_types=1);

namespace App\Application\Codex\Contracts;

use App\Application\Codex\Data\CodexRuntimeIdentity;
use App\Domain\Codex\CodexRuntimeState;
use App\Models\ProviderSession;

/**
 * Provides safe local process inspection and bounded recovery termination.
 */
interface CodexRuntimeControl
{
    /**
     * Capture immutable identity for one currently running local process.
     */
    public function captureIdentity(
        int $processId,
    ): ?CodexRuntimeIdentity;

    /**
     * Determine whether the exact recorded process is still running.
     */
    public function inspect(
        ProviderSession $session,
    ): CodexRuntimeState;

    /**
     * Terminate only the exact recorded process after identity verification.
     */
    public function terminate(
        ProviderSession $session,
        int $graceSeconds,
    ): CodexRuntimeState;
}
