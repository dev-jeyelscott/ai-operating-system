<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Events\Data\DeadLetterRecord;
use App\Application\Events\DeadLetterManager;
use App\Domain\Executions\ExecutionStatus;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Organization;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds a tenant-scoped blocker and recovery read model.
 */
final readonly class GetProjectRecoveryCenter
{
    private const int MAX_EXECUTIONS = 100;

    private const int MAX_DEAD_LETTERS = 50;

    /**
     * Inject the existing safe dead-letter manager.
     */
    public function __construct(
        private DeadLetterManager $deadLetters,
    ) {}

    /**
     * Return blocked, retrying, failed, and dead-lettered project work.
     *
     * @return array<string, mixed>
     */
    public function handle(
        int $organizationId,
        int $projectId,
    ): array {
        $asOf = CarbonImmutable::now();

        $organization = Organization::query()
            ->whereKey($organizationId)
            ->firstOrFail();

        $project = Project::query()
            ->forOrganization($organization->id)
            ->whereKey($projectId)
            ->firstOrFail();

        $executions = Execution::query()
            ->forProject($project->id)
            ->whereIn('status', [
                ExecutionStatus::Blocked->value,
                ExecutionStatus::RetryScheduled->value,
                ExecutionStatus::Failed->value,
            ])
            ->latest('updated_at')
            ->limit(self::MAX_EXECUTIONS)
            ->get();

        $latestAttempts = $this->latestAttempts(
            array_values($executions->modelKeys()),
        );

        $executionRows = [];

        foreach ($executions as $execution) {
            $attempt = $latestAttempts->get($execution->id);

            $executionRows[] = $this->serializeExecution(
                organization: $organization,
                project: $project,
                execution: $execution,
                attempt: $attempt instanceof ExecutionAttempt
                    ? $attempt
                    : null,
            );
        }

        $deadLetterRows = array_map(
            fn (DeadLetterRecord $record): array => [
                'source' => $record->source->value,
                'id' => $record->id,
                'eventId' => $record->eventId,
                'eventName' => $record->eventName ?? 'unknown',
                'attempts' => $record->attempts,
                'failedAt' => $record->failedAt->toIso8601String(),
                'errorType' => $record->errorType,
            ],
            $this->deadLetters->inspectForProject(
                organizationId: $organization->id,
                projectId: $project->id,
                limit: self::MAX_DEAD_LETTERS,
            ),
        );

        $summary = [
            'blocked' => count(array_filter(
                $executionRows,
                static fn (array $row): bool => $row['kind'] === 'blocked',
            )),
            'retryScheduled' => count(array_filter(
                $executionRows,
                static fn (array $row): bool => $row['kind'] === 'retry',
            )),
            'failed' => count(array_filter(
                $executionRows,
                static fn (array $row): bool => $row['kind'] === 'failed',
            )),
            'deadLetters' => count($deadLetterRows),
        ];

        $core = [
            'summary' => $summary,
            'executions' => $executionRows,
            'deadLetters' => $deadLetterRows,
        ];

        return [
            'metadata' => [
                'asOf' => $asOf->toIso8601String(),
                'fingerprint' => hash(
                    'sha256',
                    json_encode($core, JSON_THROW_ON_ERROR),
                ),
            ],
            ...$core,
        ];
    }

    /**
     * Return the greatest attempt number for each selected execution.
     *
     * @param  list<int|string>  $executionIds
     * @return Collection<int|string, ExecutionAttempt>
     */
    private function latestAttempts(array $executionIds): Collection
    {
        if ($executionIds === []) {
            return collect();
        }

        return ExecutionAttempt::query()
            ->whereIn('execution_id', $executionIds)
            ->orderBy('execution_id')
            ->orderByDesc('attempt_number')
            ->get()
            ->unique('execution_id')
            ->keyBy('execution_id');
    }

    /**
     * Serialize one execution without exposing provider secrets or raw data.
     *
     * @return array<string, mixed>
     */
    private function serializeExecution(
        Organization $organization,
        Project $project,
        Execution $execution,
        ?ExecutionAttempt $attempt,
    ): array {
        $kind = match ($execution->status) {
            ExecutionStatus::Blocked => 'blocked',
            ExecutionStatus::RetryScheduled => 'retry',
            default => 'failed',
        };

        return [
            'id' => $execution->id,
            'kind' => $kind,
            'status' => $execution->status->value,
            'capability' => $execution->capability,
            'logicalRole' => $execution->logical_role,
            'provider' => $attempt?->execution_provider,
            'attemptCount' => $execution->attempt_count,
            'retryLimit' => $execution->retry_limit,
            'nextAttemptAt' => $execution
                ->next_attempt_at
                ?->toIso8601String(),
            'errorCode' => $attempt?->error_code,
            'errorMessage' => $attempt?->error_message,
            'retryable' => $attempt?->retryable,
            'finishedAt' => $attempt
                ?->finished_at
                ?->toIso8601String(),
            'simulated' => $attempt?->execution_provider === 'simulation',
            'recommendedAction' => match ($kind) {
                'retry' => 'Wait for the bounded retry or inspect the execution before intervening.',
                'blocked' => 'Review the blocker and any required human decision before resuming work.',
                default => 'Inspect the final attempt and create approved follow-up work if recovery is required.',
            },
            'contextUrl' => route(
                'organizations.projects.audit.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'execution' => $execution->id,
                ],
                false,
            ).'#execution-'.$execution->id,
        ];
    }
}
