<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Domain\Executions\ExecutionAttemptStatus;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketLeaseReleaseReason;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\TicketExecutionLease;
use App\Models\WorkflowInstance;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Builds tenant-scoped operational metrics from authoritative workflow state.
 *
 * This query does not create another source of truth. Every metric is derived
 * from durable execution, attempt, lease, workflow, and outbox records.
 */
final readonly class GetProjectOperationalMetrics
{
    private const int SCHEMA_VERSION = 1;

    /**
     * Return operational metrics for one authorized organization project.
     *
     * @return array<string, mixed>
     */
    public function handle(
        int $organizationId,
        int $projectId,
    ): array {
        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();

        $asOf = CarbonImmutable::now();

        $windowDays = max(
            1,
            (int) config('operational-metrics.window_days', 7),
        );

        $windowStartedAt = $asOf->subDays($windowDays);

        $queueLongWaitSeconds = max(
            1,
            (int) config(
                'operational-metrics.queue_long_wait_seconds',
                60,
            ),
        );

        $leaseStaleAfterSeconds = max(
            1,
            (int) config(
                'operational-metrics.lease_stale_after_seconds',
                120,
            ),
        );

        $executions = $this->executions(
            projectId: $project->id,
            windowStartedAt: $windowStartedAt,
        );

        $attempts = $this->attempts(
            executionIds: array_values($executions->modelKeys()),
        );

        $leases = $this->leases(
            projectId: $project->id,
            windowStartedAt: $windowStartedAt,
        );

        $workflows = $this->workflows(
            projectId: $project->id,
            windowStartedAt: $windowStartedAt,
        );

        $outboxMessages = $this->outboxMessages(
            projectId: $project->id,
            windowStartedAt: $windowStartedAt,
        );

        $queueWaitDurations = $this->executionQueueWaitDurations(
            $executions,
        );

        $executionDurations = $this->executionRunDurations(
            $executions,
        );

        $workflowDurations = $this->workflowDurations(
            $workflows,
        );

        $queuedExecutions = $executions
            ->filter(
                static fn (Execution $execution): bool => $execution->status === ExecutionStatus::Queued,
            )
            ->sortBy('created_at')
            ->values();

        /** @var Execution|null $oldestQueuedExecution */
        $oldestQueuedExecution = $queuedExecutions->first();

        $oldestQueueAgeSeconds = $oldestQueuedExecution instanceof Execution
            ? $this->secondsBetween(
                $oldestQueuedExecution->created_at,
                $asOf,
            )
            : null;

        $longWaitingExecutions = $queuedExecutions
            ->filter(
                fn (Execution $execution): bool => ($this->secondsBetween(
                    $execution->created_at,
                    $asOf,
                ) ?? 0.0) >= $queueLongWaitSeconds,
            )
            ->count();

        $completedExecutions = $executions
            ->filter(
                static fn (Execution $execution): bool => $execution->status === ExecutionStatus::Completed,
            )
            ->count();

        $failedExecutions = $executions
            ->filter(
                static fn (Execution $execution): bool => $execution->status === ExecutionStatus::Failed,
            )
            ->count();

        $activeExecutions = $executions
            ->filter(
                static fn (Execution $execution): bool => ! $execution->status->isTerminal(),
            )
            ->count();

        $retryScheduledExecutions = $executions
            ->filter(
                static fn (Execution $execution): bool => $execution->status === ExecutionStatus::RetryScheduled,
            )
            ->count();

        $retryAttempts = $attempts
            ->filter(
                static fn (ExecutionAttempt $attempt): bool => $attempt->attempt_number > 1,
            )
            ->count();

        $failedAttempts = $attempts
            ->filter(
                static fn (ExecutionAttempt $attempt): bool => $attempt->status === ExecutionAttemptStatus::Failed,
            )
            ->count();

        $timedOutAttempts = $attempts
            ->filter(
                static fn (ExecutionAttempt $attempt): bool => $attempt->status === ExecutionAttemptStatus::TimedOut,
            )
            ->count();

        $activeLeases = $leases
            ->filter(
                static fn (TicketExecutionLease $lease): bool => $lease->isActive(),
            );

        $expiredActiveLeases = $activeLeases
            ->filter(
                static fn (TicketExecutionLease $lease): bool => $lease->expires_at->lessThanOrEqualTo($asOf),
            )
            ->count();

        $staleHeartbeatLeases = $activeLeases
            ->filter(
                fn (TicketExecutionLease $lease): bool => ($this->secondsBetween(
                    $lease->heartbeat_at,
                    $asOf,
                ) ?? 0.0) >= $leaseStaleAfterSeconds,
            )
            ->count();

        $manualRecoveryReleases = $leases
            ->filter(
                static fn (TicketExecutionLease $lease): bool => $lease->release_reason
                    === TicketLeaseReleaseReason::ManualRecovery,
            )
            ->count();

        $activeWorkflows = $workflows
            ->filter(
                static fn (WorkflowInstance $workflow): bool => $workflow->completed_at === null,
            )
            ->count();

        $completedWorkflows = $workflows
            ->filter(
                static fn (WorkflowInstance $workflow): bool => $workflow->completed_at !== null,
            )
            ->count();

        $blockedWorkflows = $workflows
            ->filter(
                static fn (WorkflowInstance $workflow): bool => $workflow->current_state === 'blocked',
            )
            ->count();

        $currentDeadLetters = $outboxMessages
            ->filter(
                static fn (OutboxMessage $message): bool => $message->dead_lettered_at !== null
                    && $message->published_at === null,
            );

        $replayedDeadLetters = $outboxMessages
            ->filter(
                static fn (OutboxMessage $message): bool => $message->replay_count > 0,
            )
            ->count();

        /** @var OutboxMessage|null $oldestDeadLetter */
        $oldestDeadLetter = $currentDeadLetters
            ->sortBy('dead_lettered_at')
            ->first();

        return [
            'metadata' => [
                'schemaVersion' => self::SCHEMA_VERSION,
                'asOf' => $asOf->toIso8601String(),
                'windowStartedAt' => $windowStartedAt->toIso8601String(),
                'windowDays' => $windowDays,
            ],

            'queue' => [
                'currentlyQueued' => $queuedExecutions->count(),
                'longWaiting' => $longWaitingExecutions,
                'longWaitThresholdSeconds' => $queueLongWaitSeconds,
                'oldestQueuedAt' => $oldestQueuedExecution
                    ?->created_at
                    ?->toIso8601String(),
                'oldestQueueAgeSeconds' => $this->roundMetric(
                    $oldestQueueAgeSeconds,
                ),
                'averageWaitSeconds' => $this->average(
                    $queueWaitDurations,
                ),
                'p95WaitSeconds' => $this->percentile(
                    $queueWaitDurations,
                    0.95,
                ),
            ],

            'executions' => [
                'total' => $executions->count(),
                'active' => $activeExecutions,
                'completed' => $completedExecutions,
                'failed' => $failedExecutions,
                'retryScheduled' => $retryScheduledExecutions,
                'completionRate' => $this->ratio(
                    $completedExecutions,
                    $executions->count(),
                ),
                'failureRate' => $this->ratio(
                    $failedExecutions,
                    $executions->count(),
                ),
                'averageDurationSeconds' => $this->average(
                    $executionDurations,
                ),
                'p95DurationSeconds' => $this->percentile(
                    $executionDurations,
                    0.95,
                ),
            ],

            'attempts' => [
                'total' => $attempts->count(),
                'retries' => $retryAttempts,
                'failed' => $failedAttempts,
                'timedOut' => $timedOutAttempts,
                'retryRate' => $this->ratio(
                    $retryAttempts,
                    $attempts->count(),
                ),
            ],

            'leases' => [
                'total' => $leases->count(),
                'active' => $activeLeases->count(),
                'expiredActive' => $expiredActiveLeases,
                'staleHeartbeat' => $staleHeartbeatLeases,
                'released' => $leases->count() - $activeLeases->count(),
                'manualRecoveryReleases' => $manualRecoveryReleases,
                'staleAfterSeconds' => $leaseStaleAfterSeconds,
            ],

            'workflows' => [
                'total' => $workflows->count(),
                'active' => $activeWorkflows,
                'blocked' => $blockedWorkflows,
                'completed' => $completedWorkflows,
                'completionRate' => $this->ratio(
                    $completedWorkflows,
                    $workflows->count(),
                ),
                'averageDurationSeconds' => $this->average(
                    $workflowDurations,
                ),
                'p95DurationSeconds' => $this->percentile(
                    $workflowDurations,
                    0.95,
                ),
            ],

            'deadLetters' => [
                'current' => $currentDeadLetters->count(),
                'replayed' => $replayedDeadLetters,
                'dispatchAttempts' => $outboxMessages->sum(
                    static fn (OutboxMessage $message): int => $message->dispatch_attempts,
                ),
                'oldestDeadLetteredAt' => $oldestDeadLetter
                    ?->dead_lettered_at
                    ?->toIso8601String(),
            ],
        ];
    }

    /**
     * Load recent executions and all currently active executions.
     *
     * @return EloquentCollection<int, Execution>
     */
    private function executions(
        int $projectId,
        CarbonImmutable $windowStartedAt,
    ): EloquentCollection {
        return Execution::query()
            ->forProject($projectId)
            ->where(
                static function (Builder $query) use (
                    $windowStartedAt,
                ): void {
                    $query
                        ->where('created_at', '>=', $windowStartedAt)
                        ->orWhereNotIn('status', [
                            ExecutionStatus::Completed->value,
                            ExecutionStatus::Failed->value,
                            ExecutionStatus::Cancelled->value,
                        ]);
                },
            )
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Load attempts belonging to the selected execution records.
     *
     * @param  list<int|string>  $executionIds
     * @return Collection<int, ExecutionAttempt>
     */
    private function attempts(array $executionIds): Collection
    {
        if ($executionIds === []) {
            return collect();
        }

        return ExecutionAttempt::query()
            ->whereIn('execution_id', $executionIds)
            ->orderBy('execution_id')
            ->orderBy('attempt_number')
            ->get();
    }

    /**
     * Load recent leases and every currently active lease.
     *
     * @return Collection<int, TicketExecutionLease>
     */
    private function leases(
        int $projectId,
        CarbonImmutable $windowStartedAt,
    ): Collection {
        return TicketExecutionLease::query()
            ->where('project_id', $projectId)
            ->where(
                static function (Builder $query) use (
                    $windowStartedAt,
                ): void {
                    $query
                        ->where('acquired_at', '>=', $windowStartedAt)
                        ->orWhereNull('released_at');
                },
            )
            ->orderBy('acquired_at')
            ->get();
    }

    /**
     * Load recent workflows and every incomplete workflow.
     *
     * @return Collection<int, WorkflowInstance>
     */
    private function workflows(
        int $projectId,
        CarbonImmutable $windowStartedAt,
    ): Collection {
        return WorkflowInstance::query()
            ->where('project_id', $projectId)
            ->where(
                static function (Builder $query) use (
                    $windowStartedAt,
                ): void {
                    $query
                        ->where('created_at', '>=', $windowStartedAt)
                        ->orWhereNull('completed_at');
                },
            )
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Load recent outbox messages and unresolved dead letters.
     *
     * @return Collection<int, OutboxMessage>
     */
    private function outboxMessages(
        int $projectId,
        CarbonImmutable $windowStartedAt,
    ): Collection {
        return OutboxMessage::query()
            ->where('project_id', $projectId)
            ->where(
                static function (Builder $query) use (
                    $windowStartedAt,
                ): void {
                    $query
                        ->where('created_at', '>=', $windowStartedAt)
                        ->orWhere(
                            static function (Builder $query): void {
                                $query
                                    ->whereNotNull('dead_lettered_at')
                                    ->whereNull('published_at');
                            },
                        );
                },
            )
            ->orderBy('sequence')
            ->get();
    }

    /**
     * Return completed execution queue-wait durations.
     *
     * @param  Collection<int, Execution>  $executions
     * @return list<float>
     */
    private function executionQueueWaitDurations(
        Collection $executions,
    ): array {
        $durations = [];

        foreach ($executions as $execution) {
            $duration = $this->secondsBetween(
                $execution->created_at,
                $execution->started_at,
            );

            if ($duration !== null) {
                $durations[] = $duration;
            }
        }

        return $durations;
    }

    /**
     * Return completed execution runtime durations.
     *
     * @param  Collection<int, Execution>  $executions
     * @return list<float>
     */
    private function executionRunDurations(
        Collection $executions,
    ): array {
        $durations = [];

        foreach ($executions as $execution) {
            $duration = $this->secondsBetween(
                $execution->started_at,
                $execution->finished_at,
            );

            if ($duration !== null) {
                $durations[] = $duration;
            }
        }

        return $durations;
    }

    /**
     * Return completed workflow durations.
     *
     * @param  Collection<int, WorkflowInstance>  $workflows
     * @return list<float>
     */
    private function workflowDurations(
        Collection $workflows,
    ): array {
        $durations = [];

        foreach ($workflows as $workflow) {
            $duration = $this->secondsBetween(
                $workflow->created_at,
                $workflow->completed_at,
            );

            if ($duration !== null) {
                $durations[] = $duration;
            }
        }

        return $durations;
    }

    /**
     * Return non-negative seconds between two timestamps.
     */
    private function secondsBetween(
        ?CarbonInterface $startedAt,
        ?CarbonInterface $finishedAt,
    ): ?float {
        if ($startedAt === null || $finishedAt === null) {
            return null;
        }

        return max(
            0.0,
            (
                $finishedAt->getTimestampMs()
                - $startedAt->getTimestampMs()
            ) / 1000,
        );
    }

    /**
     * Calculate an arithmetic mean for a metric set.
     *
     * @param  list<float>  $values
     */
    private function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(
            array_sum($values) / count($values),
            2,
        );
    }

    /**
     * Calculate a nearest-rank percentile for a metric set.
     *
     * @param  list<float>  $values
     */
    private function percentile(
        array $values,
        float $percentile,
    ): ?float {
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);

        $rank = max(
            0,
            min(
                count($values) - 1,
                (int) ceil($percentile * count($values)) - 1,
            ),
        );

        return round($values[$rank], 2);
    }

    /**
     * Return a bounded decimal ratio.
     */
    private function ratio(
        int $numerator,
        int $denominator,
    ): float {
        if ($denominator === 0) {
            return 0.0;
        }

        return round(
            min(1.0, max(0.0, $numerator / $denominator)),
            4,
        );
    }

    /**
     * Round an optional metric consistently.
     */
    private function roundMetric(?float $value): ?float
    {
        return $value === null
            ? null
            : round($value, 2);
    }
}
