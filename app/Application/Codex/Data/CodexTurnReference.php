<?php

declare(strict_types=1);

namespace App\Application\Codex\Data;

/**
 * References one provider-owned Codex turn.
 */
final readonly class CodexTurnReference
{
    /**
     * Create one immutable turn reference.
     */
    public function __construct(
        public string $id,
        public string $status,
    ) {}
}
