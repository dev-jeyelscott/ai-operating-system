<?php

declare(strict_types=1);

namespace App\Application\Development;

use App\Application\Tickets\Data\TicketEligibilityContext;
use App\Application\Tickets\Data\TicketExecutionPolicyFacts;
use App\Application\Tickets\Data\TicketRankingContext;
use App\Application\Tickets\TicketEligibilityEvaluator;
use App\Application\Tickets\TicketExecutionPolicyResolver;
use App\Application\Tickets\TicketRanker;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketIneligibilityReason;
use App\Domain\Tickets\TicketStatus;
use App\Models\Execution;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use App\Models\TaskDependency;
use App\Models\TicketExecutionLease;
use Carbon\CarbonImmutable;

final readonly class ListProjectDevelopmentQueue
{
    public function __construct(
        private TicketEligibilityEvaluator $eligibility,
        private TicketRanker $ranker,
        private TicketExecutionPolicyResolver $policyResolver,
    ) {}

    /** @return array<string, mixed> */
    public function handle(int $organizationId, int $projectId): array
    {
        $asOf = CarbonImmutable::now();
        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();
        $roadmap = Roadmap::query()
            ->where('project_id', $project->id)
            ->where('status', 'approved')
            ->whereNotNull('approved_at')
            ->latest('revision')
            ->first();

        if ($roadmap === null) {
            return $this->emptyQueue($asOf);
        }

        $tickets = RoadmapTask::query()
            ->where('roadmap_id', $roadmap->id)
            ->with('dependencies.dependsOn')
            ->orderBy('position')
            ->orderBy('stable_id')
            ->get();
        $leases = TicketExecutionLease::query()
            ->where('project_id', $project->id)
            ->whereIn('roadmap_task_id', $tickets->modelKeys())
            ->with(['execution', 'ticket:id,stable_id'])
            ->orderByDesc('acquired_at')
            ->get();
        $latestLeaseByTicket = $leases->unique('roadmap_task_id')->keyBy('roadmap_task_id');
        $activeLeaseByTicket = $leases->whereNull('released_at')->keyBy('roadmap_task_id');
        $basePolicy = $this->policyResolver->resolveForContext(
            project: $project,
            contextSnapshotId: $roadmap->project_context_snapshot_id,
        );
        $workableById = [];
        $serialized = [];

        foreach ($tickets as $ticket) {
            $lease = $latestLeaseByTicket->get($ticket->id);
            $execution = $lease instanceof TicketExecutionLease ? $lease->execution : null;
            $policy = $execution instanceof Execution
                ? $basePolicy->withAttemptPolicy($execution->attempt_count, $execution->retry_limit)
                : $basePolicy;
            $result = $this->eligibility->evaluate($this->eligibilityContext($ticket, $project, $policy));
            $reasons = array_map(
                static fn (TicketIneligibilityReason $reason): string => $reason->value,
                $result->reasons,
            );

            if ($activeLeaseByTicket->has($ticket->id)) {
                $reasons[] = TicketIneligibilityReason::ActiveLease->value;
            }

            $serialized[$ticket->stable_id] = $this->serializeTicket(
                ticket: $ticket,
                lease: $lease instanceof TicketExecutionLease ? $lease : null,
                execution: $execution,
                policy: $policy,
                reasons: array_values(array_unique($reasons)),
            );

            if ($reasons === []) {
                $workableById[$ticket->stable_id] = $this->rankingContext($ticket);
            }
        }

        $workable = array_map(
            static fn (TicketRankingContext $ranked): array => $serialized[$ranked->ticketId],
            $this->ranker->rank(array_values($workableById)),
        );
        $ineligible = array_values(array_filter(
            $serialized,
            static fn (array $ticket): bool => $ticket['ineligibilityReasonCodes'] !== [],
        ));
        $activeLeases = array_values(array_map(
            fn (TicketExecutionLease $lease): array => $this->serializeLease($lease),
            $activeLeaseByTicket->values()->all(),
        ));
        $retryScheduled = array_values(array_filter(
            $serialized,
            static fn (array $ticket): bool => $ticket['executionState'] === ExecutionStatus::RetryScheduled->value,
        ));
        $fingerprint = hash('sha256', json_encode([
            'roadmap' => [$roadmap->id, $roadmap->revision, $roadmap->approved_fingerprint],
            'workable' => $workable,
            'ineligible' => $ineligible,
            'leases' => $activeLeases,
        ], JSON_THROW_ON_ERROR));

        return [
            'metadata' => [
                'asOf' => $asOf->toISOString(),
                'approvedRoadmapId' => $roadmap->id,
                'approvedRoadmapRevision' => $roadmap->revision,
                'queueFingerprint' => $fingerprint,
                'noWorkableTicket' => $workable === [],
            ],
            'workable' => $workable,
            'ineligible' => $ineligible,
            'activeLeases' => $activeLeases,
            'retryScheduled' => $retryScheduled,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyQueue(CarbonImmutable $asOf): array
    {
        return [
            'metadata' => [
                'asOf' => $asOf->toISOString(), 'approvedRoadmapId' => null,
                'approvedRoadmapRevision' => null, 'queueFingerprint' => null,
                'noWorkableTicket' => false,
            ],
            'workable' => [], 'ineligible' => [], 'activeLeases' => [], 'retryScheduled' => [],
        ];
    }

    private function eligibilityContext(
        RoadmapTask $ticket,
        Project $project,
        TicketExecutionPolicyFacts $policy,
    ): TicketEligibilityContext {
        $approvalGranted = $policy->approvalGrantedFor($ticket);

        return new TicketEligibilityContext(
            status: $ticket->status,
            changesRequestedApproved: $approvalGranted,
            hardDependencyStatuses: array_values($ticket->dependencies->map(
                static fn (TaskDependency $dependency): TicketStatus => $dependency->dependsOn->status,
            )->all()),
            hasUnresolvedBlocker: $ticket->status === TicketStatus::Blocked
                || in_array('blocked', array_map(
                    static fn (?string $state): string => strtolower(trim((string) $state)),
                    [$ticket->reported_state, $ticket->observed_state],
                ), true),
            approvalRequired: $ticket->human_approval_required,
            approvalGranted: $approvalGranted,
            projectStatus: $project->status,
            providerSupportsExecution: $policy->providerSupportsExecution,
            budgetPermitsExecution: $policy->budgetPermitsExecution,
            attemptCount: $policy->attemptCount,
            retryLimit: $policy->retryLimit,
        );
    }

    private function rankingContext(RoadmapTask $ticket): TicketRankingContext
    {
        return new TicketRankingContext(
            ticketId: $ticket->stable_id,
            roadmapOrder: $ticket->position,
            isCriticalPath: $ticket->is_critical_path,
            criticalPathRank: $ticket->critical_path_rank ?? 0,
            priority: strtolower($ticket->priority),
            explicitSequence: $ticket->position,
            riskPolicyRank: match (strtolower($ticket->risk)) {
                'low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3, default => 99,
            },
            readyAt: ($ticket->ready_at ?? $ticket->status_changed_at)->toDateTimeImmutable(),
            estimatedEffort: $ticket->estimated_complexity,
        );
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function serializeTicket(
        RoadmapTask $ticket,
        ?TicketExecutionLease $lease,
        ?Execution $execution,
        TicketExecutionPolicyFacts $policy,
        array $reasons,
    ): array {
        return [
            'id' => $ticket->stable_id, 'title' => $ticket->title,
            'objectiveSummary' => $ticket->objective, 'priority' => strtolower($ticket->priority),
            'risk' => strtolower($ticket->risk), 'roadmapPosition' => $ticket->position,
            'criticalPath' => ['active' => $ticket->is_critical_path, 'rank' => $ticket->critical_path_rank],
            'status' => $ticket->status->value, 'desiredState' => $ticket->desired_state->value,
            'reportedState' => $ticket->reported_state, 'observedState' => $ticket->observed_state,
            'actualState' => $ticket->actual_state->value,
            'dependencies' => $ticket->dependencies->map(static fn (TaskDependency $dependency): array => [
                'id' => $dependency->dependsOn->stable_id, 'title' => $dependency->dependsOn->title,
                'status' => $dependency->dependsOn->status->value,
            ])->values()->all(),
            'approvalState' => ! $ticket->human_approval_required ? 'not_required' : ($policy->approvalGrantedFor($ticket) ? 'approved' : 'pending'),
            'activeLease' => $lease?->isActive() ?? false, 'leaseExpiresAt' => $lease?->isActive() ? $lease->expires_at->toISOString() : null,
            'executionState' => $execution?->status->value, 'attemptCount' => $execution instanceof Execution ? $execution->attempt_count : 0,
            'retryLimit' => $execution instanceof Execution ? $execution->retry_limit : $policy->retryLimit,
            'nextAttemptAt' => $execution?->next_attempt_at?->toISOString(),
            'providerAvailable' => $policy->providerSupportsExecution,
            'budgetAvailable' => $policy->budgetPermitsExecution,
            'ineligibilityReasonCodes' => $reasons,
            'inspectorExecutionId' => $execution?->id,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeLease(TicketExecutionLease $lease): array
    {
        return [
            'ticketId' => $lease->ticket->stable_id,
            'expiresAt' => $lease->expires_at->toISOString(),
            'expired' => $lease->expires_at->isPast(),
        ];
    }
}
