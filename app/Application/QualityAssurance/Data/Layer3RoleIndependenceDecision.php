<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Data;

use App\Domain\QualityAssurance\Layer3RoleIndependenceFailureReason;

/**
 * Represents the deterministic result of the Layer 3 independence policy.
 */
final readonly class Layer3RoleIndependenceDecision
{
    /**
     * Create one immutable policy decision.
     */
    private function __construct(
        public bool $allowed,
        public ?Layer3RoleIndependenceFailureReason $reason,
    ) {}

    /**
     * Create an allowed decision for an independent reviewer execution.
     */
    public static function allowed(): self
    {
        return new self(
            allowed: true,
            reason: null,
        );
    }

    /**
     * Create a rejected decision with a stable machine-readable reason.
     */
    public static function rejected(
        Layer3RoleIndependenceFailureReason $reason,
    ): self {
        return new self(
            allowed: false,
            reason: $reason,
        );
    }
}
