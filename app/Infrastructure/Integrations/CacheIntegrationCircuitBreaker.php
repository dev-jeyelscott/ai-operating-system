<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use App\Application\Integrations\Contracts\IntegrationCircuitBreaker;
use App\Application\Integrations\Exceptions\IntegrationCircuitOpen;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores shared integration circuit state in Laravel's configured cache.
 *
 * Production and normal development environments use Redis, providing atomic
 * locks and shared state across Horizon workers and application instances.
 */
final class CacheIntegrationCircuitBreaker implements IntegrationCircuitBreaker
{
    /**
     * Reject calls while open and reserve one half-open recovery probe.
     */
    public function assertCanAttempt(
        string $provider,
        string $scope,
    ): void {
        try {
            $this->withLock(
                provider: $provider,
                scope: $scope,
                callback: function (
                    string $stateKey,
                    string $circuitId,
                ) use ($provider): void {
                    $state = Cache::get($stateKey);

                    if (! is_array($state)) {
                        return;
                    }

                    $phase = $state['phase'] ?? null;
                    $now = now()->getTimestamp();

                    if ($phase === 'closed') {
                        return;
                    }

                    if ($phase === 'open') {
                        $retryAt = $this->stateInteger(
                            state: $state,
                            key: 'retry_at',
                        );

                        if ($retryAt > $now) {
                            throw new IntegrationCircuitOpen(
                                provider: $provider,
                                retryAfterSeconds: max(
                                    1,
                                    $retryAt - $now,
                                ),
                            );
                        }

                        $this->storeHalfOpenState(
                            stateKey: $stateKey,
                            now: $now,
                        );

                        Log::notice(
                            'integration_circuit_half_open',
                            $this->logContext(
                                provider: $provider,
                                circuitId: $circuitId,
                                phase: 'half_open',
                            ),
                        );

                        return;
                    }

                    if ($phase === 'half_open') {
                        $probeExpiresAt = $this->stateInteger(
                            state: $state,
                            key: 'probe_expires_at',
                        );

                        if ($probeExpiresAt > $now) {
                            throw new IntegrationCircuitOpen(
                                provider: $provider,
                                retryAfterSeconds: max(
                                    1,
                                    $probeExpiresAt - $now,
                                ),
                            );
                        }

                        /*
                         * The previous probe lease expired without recording a
                         * result. Reserve a new probe instead of remaining stuck.
                         */
                        $this->storeHalfOpenState(
                            stateKey: $stateKey,
                            now: $now,
                        );

                        return;
                    }

                    /*
                     * Unknown or malformed state fails back to a clean circuit.
                     */
                    Cache::forget($stateKey);
                },
            );
        } catch (IntegrationCircuitOpen $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            /*
             * Queue execution already depends on Redis. Failing closed here is
             * safer than creating an uncontrolled external request storm.
             */
            Log::warning('integration_circuit_state_unavailable', [
                'provider' => $provider,
                'exception' => $exception::class,
            ]);

            throw new IntegrationCircuitOpen(
                provider: $provider,
                retryAfterSeconds: 1,
            );
        }
    }

    /**
     * Close the circuit after a successful provider operation.
     */
    public function recordSuccess(
        string $provider,
        string $scope,
    ): void {
        try {
            $this->withLock(
                provider: $provider,
                scope: $scope,
                callback: function (
                    string $stateKey,
                    string $circuitId,
                ) use ($provider): void {
                    $previousState = Cache::get($stateKey);

                    Cache::forget($stateKey);

                    if (is_array($previousState)) {
                        Log::notice(
                            'integration_circuit_closed',
                            $this->logContext(
                                provider: $provider,
                                circuitId: $circuitId,
                                phase: 'closed',
                            ),
                        );
                    }
                },
            );
        } catch (Throwable $exception) {
            /*
             * Do not convert an otherwise successful provider operation into a
             * failure solely because the transient health record could not be
             * cleared. Surface the cache issue through structured logs.
             */
            Log::warning('integration_circuit_success_not_recorded', [
                'provider' => $provider,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Increment the closed-state failure counter or reopen a half-open circuit.
     */
    public function recordFailure(
        string $provider,
        string $scope,
    ): void {
        try {
            $this->withLock(
                provider: $provider,
                scope: $scope,
                callback: function (
                    string $stateKey,
                    string $circuitId,
                ) use ($provider): void {
                    $state = Cache::get($stateKey);
                    $state = is_array($state) ? $state : [];

                    $phase = $state['phase'] ?? 'closed';
                    $now = now()->getTimestamp();

                    if ($phase === 'open') {
                        /*
                         * Late responses from calls admitted before opening must
                         * not continuously extend an already open circuit.
                         */
                        $retryAt = $this->stateInteger(
                            state: $state,
                            key: 'retry_at',
                        );

                        if ($retryAt > $now) {
                            return;
                        }
                    }

                    if (
                        $phase === 'half_open'
                        || $phase === 'open'
                    ) {
                        $this->openCircuit(
                            provider: $provider,
                            circuitId: $circuitId,
                            stateKey: $stateKey,
                            failureCount: $this->failureThreshold(),
                            now: $now,
                        );

                        return;
                    }

                    $windowStartedAt = $this->stateInteger(
                        state: $state,
                        key: 'window_started_at',
                    );

                    $failureCount = $this->stateInteger(
                        state: $state,
                        key: 'failure_count',
                    );

                    if (
                        $windowStartedAt === 0
                        || ($now - $windowStartedAt)
                            >= $this->failureWindowSeconds()
                    ) {
                        $windowStartedAt = $now;
                        $failureCount = 0;
                    }

                    $failureCount++;

                    if ($failureCount >= $this->failureThreshold()) {
                        $this->openCircuit(
                            provider: $provider,
                            circuitId: $circuitId,
                            stateKey: $stateKey,
                            failureCount: $failureCount,
                            now: $now,
                        );

                        return;
                    }

                    $this->putState(
                        stateKey: $stateKey,
                        state: [
                            'phase' => 'closed',
                            'failure_count' => $failureCount,
                            'window_started_at' => $windowStartedAt,
                        ],
                    );
                },
            );
        } catch (Throwable $exception) {
            /*
             * Preserve the original sanitized provider failure. Circuit state
             * persistence problems are operationally visible through the log.
             */
            Log::warning('integration_circuit_failure_not_recorded', [
                'provider' => $provider,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Move a circuit to the open state and record a safe retry timestamp.
     */
    private function openCircuit(
        string $provider,
        string $circuitId,
        string $stateKey,
        int $failureCount,
        int $now,
    ): void {
        $retryAfterSeconds = $this->openSeconds();

        $this->putState(
            stateKey: $stateKey,
            state: [
                'phase' => 'open',
                'failure_count' => $failureCount,
                'opened_at' => $now,
                'retry_at' => $now + $retryAfterSeconds,
            ],
        );

        Log::warning(
            'integration_circuit_opened',
            $this->logContext(
                provider: $provider,
                circuitId: $circuitId,
                phase: 'open',
                retryAfterSeconds: $retryAfterSeconds,
            ),
        );
    }

    /**
     * Reserve the only permitted half-open recovery request.
     */
    private function storeHalfOpenState(
        string $stateKey,
        int $now,
    ): void {
        $this->putState(
            stateKey: $stateKey,
            state: [
                'phase' => 'half_open',
                'probe_started_at' => $now,
                'probe_expires_at' => $now
                    + $this->halfOpenLeaseSeconds(),
            ],
        );
    }

    /**
     * Persist one circuit state with bounded retention.
     *
     * @param  array<string, int|string>  $state
     */
    private function putState(
        string $stateKey,
        array $state,
    ): void {
        Cache::put(
            key: $stateKey,
            value: $state,
            ttl: $this->retentionSeconds(),
        );
    }

    /**
     * Serialize one state transition with a distributed cache lock.
     *
     * @template TResult
     *
     * @param  Closure(string, string): TResult  $callback
     * @return TResult
     */
    private function withLock(
        string $provider,
        string $scope,
        Closure $callback,
    ): mixed {
        $circuitId = hash(
            'sha256',
            $provider.'|'.$scope,
        );

        return Cache::lock(
            $this->lockKey($circuitId),
            $this->lockSeconds(),
        )->block(
            $this->lockWaitSeconds(),
            fn (): mixed => $callback(
                $this->stateKey($circuitId),
                $circuitId,
            ),
        );
    }

    /**
     * Build the state cache key from an irreversible identifier.
     */
    private function stateKey(string $circuitId): string
    {
        return 'integration-resilience:circuit:'.$circuitId;
    }

    /**
     * Build the atomic-lock cache key.
     */
    private function lockKey(string $circuitId): string
    {
        return 'integration-resilience:lock:'.$circuitId;
    }

    /**
     * Read a non-negative integer from persisted state.
     *
     * @param  array<string, mixed>  $state
     */
    private function stateInteger(
        array $state,
        string $key,
    ): int {
        return max(
            0,
            (int) ($state[$key] ?? 0),
        );
    }

    /**
     * Build secret-safe structured log context.
     *
     * @return array<string, int|string>
     */
    private function logContext(
        string $provider,
        string $circuitId,
        string $phase,
        int $retryAfterSeconds = 0,
    ): array {
        return [
            'provider' => $provider,
            'circuit_id' => substr($circuitId, 0, 16),
            'phase' => $phase,
            'retry_after_seconds' => $retryAfterSeconds,
        ];
    }

    /**
     * Return the configured opening threshold.
     */
    private function failureThreshold(): int
    {
        return $this->positiveConfigInteger(
            'integration-resilience.circuit.failure_threshold',
            5,
        );
    }

    /**
     * Return the configured closed-state failure window.
     */
    private function failureWindowSeconds(): int
    {
        return $this->positiveConfigInteger(
            'integration-resilience.circuit.failure_window_seconds',
            60,
        );
    }

    /**
     * Return how long an open circuit rejects requests.
     */
    private function openSeconds(): int
    {
        return $this->positiveConfigInteger(
            'integration-resilience.circuit.open_seconds',
            60,
        );
    }

    /**
     * Return the maximum duration of one half-open probe.
     */
    private function halfOpenLeaseSeconds(): int
    {
        return $this->positiveConfigInteger(
            'integration-resilience.circuit.half_open_lease_seconds',
            15,
        );
    }

    /**
     * Return the distributed transition-lock lease.
     */
    private function lockSeconds(): int
    {
        return $this->positiveConfigInteger(
            'integration-resilience.circuit.lock_seconds',
            5,
        );
    }

    /**
     * Return the maximum transition-lock wait.
     */
    private function lockWaitSeconds(): int
    {
        return $this->positiveConfigInteger(
            'integration-resilience.circuit.lock_wait_seconds',
            2,
        );
    }

    /**
     * Keep stale state only long enough for safe recovery and diagnostics.
     */
    private function retentionSeconds(): int
    {
        return max(
            $this->failureWindowSeconds(),
            $this->openSeconds(),
            $this->halfOpenLeaseSeconds(),
        ) * 3;
    }

    /**
     * Read a positive integer and fail safe for invalid configuration.
     */
    private function positiveConfigInteger(
        string $key,
        int $fallback,
    ): int {
        return max(
            1,
            (int) config($key, $fallback),
        );
    }
}
