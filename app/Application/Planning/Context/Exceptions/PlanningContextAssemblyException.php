<?php

declare(strict_types=1);

namespace App\Application\Planning\Context\Exceptions;

use RuntimeException;

/**
 * Stable deterministic failures at the provider-bound context boundary.
 */
final class PlanningContextAssemblyException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function missingRepositorySnapshot(): self
    {
        return new self(
            'planning.context.repository_snapshot_missing',
            'An immutable repository instruction snapshot is required for Codex planning.',
        );
    }

    public static function budgetExceeded(string $source): self
    {
        return new self(
            'planning.context.mandatory_content_exceeds_budget',
            "Mandatory planning source [{$source}] cannot fit within the immutable context budget.",
        );
    }

    public static function integrityFailure(string $source): self
    {
        return new self(
            'planning.context.integrity_failure',
            "Planning source [{$source}] failed immutable integrity validation.",
        );
    }
}
