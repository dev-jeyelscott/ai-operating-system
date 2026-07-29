<?php

declare(strict_types=1);

namespace App\Application\Tickets;

use App\Application\Tickets\Data\TicketExecutionPolicyFacts;
use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Approvals\ApprovalType;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use Illuminate\Support\Arr;

/**
 * Resolves selector policy from immutable context and durable decisions.
 */
final class TicketExecutionPolicyResolver
{
    private const string SIMULATION_PROVIDER = 'simulation';

    /**
     * Resolve policy once for every candidate in one locked selection attempt.
     */
    public function resolve(
        Project $project,
        Execution $execution,
    ): TicketExecutionPolicyFacts {
        return $this->resolveForContext(
            project: $project,
            contextSnapshotId: (int) $execution->project_context_snapshot_id,
            capability: $execution->capability,
            attemptCount: $execution->attempt_count,
            retryLimit: $execution->retry_limit,
        );
    }

    public function resolveForContext(
        Project $project,
        int $contextSnapshotId,
        string $capability = 'development.simulation',
        int $attemptCount = 0,
        int $retryLimit = 3,
    ): TicketExecutionPolicyFacts {
        $contextSnapshot = ProjectContextSnapshot::query()
            ->where('project_id', $project->id)
            ->whereKey($contextSnapshotId)
            ->firstOrFail();

        $configurationVersion = ProjectConfigurationVersion::query()
            ->where('project_id', $project->id)
            ->whereKey($contextSnapshot->project_configuration_version_id)
            ->where('revision', $contextSnapshot->configuration_revision)
            ->firstOrFail();

        $snapshot = $configurationVersion->snapshot;
        $allowedProviderIds = $this->stringList(
            Arr::get($snapshot, 'policy.provider.allowed_provider_ids'),
        );
        $fallbackOrder = $this->stringList(
            Arr::get($snapshot, 'policy.provider.fallback_order'),
        );
        $providerSupportsExecution = in_array(
            $capability,
            ['development', 'development.simulation'],
            true,
        ) && in_array(
            self::SIMULATION_PROVIDER,
            $allowedProviderIds,
            true,
        ) && in_array(
            self::SIMULATION_PROVIDER,
            $fallbackOrder,
            true,
        );

        $budgetLimitMinor = Arr::get(
            $snapshot,
            'policy.budget.limit_minor',
        );
        $budgetPermitsExecution = $budgetLimitMinor === null
            || (is_int($budgetLimitMinor) && $budgetLimitMinor > 0);

        $approvedRoadmapTaskIds = [];
        $approvedStableTicketIds = [];
        $approvals = Approval::query()
            ->forProject($project->id)
            ->where('type', ApprovalType::Execution->value)
            ->where('status', ApprovalStatus::Approved->value)
            ->get(['request_payload']);

        foreach ($approvals as $approval) {
            $roadmapTaskId = $approval->request_payload['roadmap_task_id'] ?? null;
            $stableTicketId = $approval->request_payload['ticket_id'] ?? null;

            if (is_int($roadmapTaskId) && $roadmapTaskId > 0) {
                $approvedRoadmapTaskIds[$roadmapTaskId] = true;
            }

            if (is_string($stableTicketId)
                && $stableTicketId !== ''
                && trim($stableTicketId) === $stableTicketId) {
                $approvedStableTicketIds[$stableTicketId] = true;
            }
        }

        return new TicketExecutionPolicyFacts(
            providerSupportsExecution: $providerSupportsExecution,
            budgetPermitsExecution: $budgetPermitsExecution,
            attemptCount: $attemptCount,
            retryLimit: $retryLimit,
            approvedRoadmapTaskIds: $approvedRoadmapTaskIds,
            approvedStableTicketIds: $approvedStableTicketIds,
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                return [];
            }

            $strings[] = $item;
        }

        return $strings;
    }
}
