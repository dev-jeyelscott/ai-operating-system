<?php

declare(strict_types=1);

namespace App\Application\Codex\Contracts;

use App\Application\Codex\Data\CodexProcessContext;

/**
 * Opens one bounded Codex App Server process for one execution attempt.
 */
interface CodexProcessGateway
{
    /**
     * Start one dedicated process without changing workflow or ticket state.
     */
    public function start(
        CodexProcessContext $context,
    ): CodexProcessSession;
}
