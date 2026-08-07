<?php

declare(strict_types=1);

namespace App\Application\Tickets;

use App\Application\Development\DevelopmentProviderRegistry;
use App\Application\Tickets\Data\TicketExecutionPolicyFacts;
use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Approvals\ApprovalType;
use App\Domain\Executions\ExecutionCapability;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\MergeDecision;
use App\Models\Project;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use LogicException;

/**
 * Resolves selector policy from immutable context and durable decisions.
 */
final class TicketExecutionPolicyResolver
{
    /**
     * Inject the provider registry used to verify real runtime availability.
     */
    public function __construct(
        private readonly DevelopmentProviderRegistry $providers,
    ) {}

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

    /**
     * Resolve execution policy from one immutable configuration context.
     */
    public function resolveForContext(
        Project $project,
        int $contextSnapshotId,
        string $capability = 'development.execute',
        int $attemptCount = 0,
        int $retryLimit = 3,
    ): TicketExecutionPolicyFacts {
        $contextSnapshot = ProjectContextSnapshot::query()
            ->where('project_id', $project->id)
            ->whereKey($contextSnapshotId)
            ->firstOrFail();

        $configurationVersion = ProjectConfigurationVersion::query()
            ->where('project_id', $project->id)
            ->whereKey(
                $contextSnapshot->project_configuration_version_id,
            )
            ->where(
                'revision',
                $contextSnapshot->configuration_revision,
            )
            ->firstOrFail();

        $snapshot = $configurationVersion->snapshot;

        $allowedProviderIds = $this->stringList(
            Arr::get(
                $snapshot,
                'policy.provider.allowed_provider_ids',
            ),
        );

        $fallbackOrder = $this->stringList(
            Arr::get(
                $snapshot,
                'policy.provider.fallback_order',
            ),
        );

        $providerSupportsExecution =
            $this->providerSupportsExecution(
                allowedProviderIds: $allowedProviderIds,
                fallbackOrder: $fallbackOrder,
                capability: $capability,
            );

        $budgetLimitMinor = Arr::get(
            $snapshot,
            'policy.budget.limit_minor',
        );

        $budgetPermitsExecution = $budgetLimitMinor === null
            || (
                is_int($budgetLimitMinor)
                && $budgetLimitMinor > 0
            );

        $approvedRoadmapTaskIds = [];
        $approvedStableTicketIds = [];

        /*
         * Preserve the existing explicit execution-approval behavior for
         * tickets whose configured policy requires a separate approval.
         */
        $approvals = Approval::query()
            ->forProject($project->id)
            ->where(
                'type',
                ApprovalType::Execution->value,
            )
            ->where(
                'status',
                ApprovalStatus::Approved->value,
            )
            ->get([
                'request_payload',
            ]);

        foreach ($approvals as $approval) {
            $roadmapTaskId =
                $approval->request_payload['roadmap_task_id']
                ?? null;

            $stableTicketId =
                $approval->request_payload['ticket_id']
                ?? null;

            if (
                is_int($roadmapTaskId)
                && $roadmapTaskId > 0
            ) {
                $approvedRoadmapTaskIds[$roadmapTaskId] = true;
            }

            if (
                is_string($stableTicketId)
                && $stableTicketId !== ''
                && trim($stableTicketId) === $stableTicketId
            ) {
                $approvedStableTicketIds[$stableTicketId] = true;
            }
        }

        /*
         * An authorized terminal request-changes decision is the approval for
         * another Layer 2 cycle. It is project-scoped and append-only, so no
         * previous execution or QA record needs to be changed.
         */
        foreach (
            $this->approvedChangesRequestedTaskIds(
                $project->id,
            ) as $roadmapTaskId
        ) {
            $approvedRoadmapTaskIds[$roadmapTaskId] = true;
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
     * Determine whether immutable policy resolves a registered development
     * provider supporting the requested canonical or historical capability.
     *
     * @param  list<string>  $allowedProviderIds
     * @param  list<string>  $fallbackOrder
     */
    private function providerSupportsExecution(
        array $allowedProviderIds,
        array $fallbackOrder,
        string $capability,
    ): bool {
        try {
            $effectiveCapability =
                ExecutionCapability::fromStored(
                    $capability,
                );
        } catch (InvalidArgumentException) {
            return false;
        }

        if (
            $effectiveCapability
            !== ExecutionCapability::DevelopmentExecute
        ) {
            return false;
        }

        $permittedFallbackOrder = array_values(
            array_filter(
                $fallbackOrder,
                static fn (
                    string $providerId,
                ): bool => in_array(
                    $providerId,
                    $allowedProviderIds,
                    true,
                ),
            ),
        );

        if ($permittedFallbackOrder === []) {
            return false;
        }

        try {
            $this->providers->resolve(
                fallbackOrder: $permittedFallbackOrder,
                capability: $effectiveCapability->value,
            );

            return true;
        } catch (
            InvalidArgumentException|LogicException) {
                return false;
            }
    }

    /**
     * Return roadmap-task IDs authorized for rework by terminal request-changes
     * merge decisions.
     *
     * @return list<int>
     */
    private function approvedChangesRequestedTaskIds(
        int $projectId,
    ): array {
        $roadmapTaskIds = MergeDecision::query()
            ->forProject($projectId)
            ->authorizesChangesRequestedRework()
            ->distinct()
            ->orderBy('roadmap_task_id')
            ->pluck('roadmap_task_id')
            ->map(
                static fn (
                    mixed $roadmapTaskId,
                ): int => (int) $roadmapTaskId,
            )
            ->filter(
                static fn (
                    int $roadmapTaskId,
                ): bool => $roadmapTaskId > 0,
            )
            ->all();

        return array_values($roadmapTaskIds);
    }

    /**
     * Normalize an untrusted configuration value into a string list.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (
            ! is_array($value)
            || ! array_is_list($value)
        ) {
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
