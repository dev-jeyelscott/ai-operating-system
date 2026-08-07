<?php

declare(strict_types=1);

namespace App\Application\Executions\Contracts;

use App\Application\Executions\Data\ExecutionProviderMetadata;

/**
 * Exposes stable provider identity, capabilities, and execution metadata.
 *
 * Typed planning, development, and QA contracts continue to own their
 * respective execute methods.
 */
interface DescribesExecutionProvider
{
    /**
     * Return the stable provider identifier used by immutable project policy.
     */
    public function id(): string;

    /**
     * Determine whether the provider supports a canonical capability.
     */
    public function supports(string $capability): bool;

    /**
     * Return metadata persisted for every selected execution attempt.
     */
    public function metadata(): ExecutionProviderMetadata;
}
