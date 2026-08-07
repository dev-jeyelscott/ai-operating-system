<?php

declare(strict_types=1);

namespace App\Domain\Workflows;

/**
 * Represents one transition resolved from an immutable workflow definition.
 */
final readonly class WorkflowTransitionDefinition
{
    /**
     * Store the exact declarative transition contract.
     */
    public function __construct(
        public string $name,
        public string $from,
        public string $to,
        public ?string $guard,
    ) {}
}
