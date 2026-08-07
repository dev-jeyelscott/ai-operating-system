<?php

declare(strict_types=1);

namespace App\Application\Integrations\Contracts;

/**
 * Protects an external integration from repeated failing calls.
 *
 * Implementations own only transient provider-health coordination. They must
 * not mutate project, workflow, publication, or ticket domain state.
 */
interface IntegrationCircuitBreaker
{
    /**
     * Fail fast when the requested provider circuit cannot accept a call.
     */
    public function assertCanAttempt(
        string $provider,
        string $scope,
    ): void;

    /**
     * Close the circuit and clear its failure history after a successful call.
     */
    public function recordSuccess(
        string $provider,
        string $scope,
    ): void;

    /**
     * Record one final provider failure after local HTTP retries are exhausted.
     */
    public function recordFailure(
        string $provider,
        string $scope,
    ): void;
}
