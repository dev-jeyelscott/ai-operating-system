<?php

declare(strict_types=1);

namespace App\Application\Codex\Data;

/**
 * References one provider-owned Codex thread.
 */
final readonly class CodexThreadReference
{
    /**
     * Create one immutable thread reference.
     */
    public function __construct(
        public string $id,
    ) {}
}
