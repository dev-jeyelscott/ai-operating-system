<?php

declare(strict_types=1);

namespace App\Application\Tickets;

use App\Application\Tickets\Data\TicketEligibilityContext;
use App\Application\Tickets\Data\TicketExecutionPolicyFacts;
use App\Application\Tickets\Data\TicketRankingContext;
use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\Data\TicketSelectionResult;
use App\Domain\Executions\ExecutionCapability;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketStatus;
use App\Models\Execution;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use App\Models\TaskDependency;
use App\Models\TicketExecutionLease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Selects the highest-ranked workable ticket and acquires its lease atomically.
 */
final readonly class SelectNextTicketAndAcquireLease
{
    /**
     * Inject the approved eligibility, ranking, and event policies.
     */
    public function __construct(
        private TicketEligibilityEvaluator $eligibility,
        private TicketRanker $ranker,
        private TicketExecutionPolicyResolver $policyResolver,
        private RecordTicketSelectionEvents $events,
    ) {}

    /**
     * Select one ticket and create one durable lease in the same transaction.
     */
    public function handle(
        TicketSelectionRequest $request,
    ): TicketSelectionResult {
        return DB::transaction(
            function () use ($request): TicketSelectionResult {
                /*
                 * The explicit organization boundary prevents cross-tenant
                 * project identifiers from entering ticket selection.
                 */
                $project = Project::query()
                    ->forOrganization($request->organizationId)
                    ->whereKey($request->projectId)
                    ->lock('for share')
                    ->firstOrFail();

                /*
                 * Lock the logical execution so attempt and retry counters
                 * cannot change while eligibility is evaluated.
                 */
                /** @var Execution $execution */
                $execution = Execution::query()
                    ->forProject($project->id)
                    ->whereKey($request->executionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->validateExecution($execution);

                $policyFacts = $this->policyResolver->resolve(
                    project: $project,
                    execution: $execution,
                );

                /*
                 * Same-execution replay returns the original selection rather
                 * than producing another lease or another set of events.
                 */
                $existingLease = TicketExecutionLease::query()
                    ->where('execution_id', $execution->id)
                    ->with('ticket.roadmap')
                    ->first();

                if ($existingLease !== null) {
                    return TicketSelectionResult::selected(
                        ticket: $existingLease->ticket,
                        lease: $existingLease,
                    );
                }

                /*
                 * Only the newest approved roadmap is eligible for execution.
                 */
                $roadmap = Roadmap::query()
                    ->where('project_id', $project->id)
                    ->where(
                        'project_context_snapshot_id',
                        $execution->project_context_snapshot_id,
                    )
                    ->where('status', 'approved')
                    ->whereNotNull('approved_at')
                    ->orderByDesc('revision')
                    ->lock('for share')
                    ->first();

                if ($roadmap === null) {
                    return TicketSelectionResult::noWorkableTicket();
                }

                /*
                 * This initial read is not the final claim. Every selected row
                 * is re-read using FOR UPDATE SKIP LOCKED before insertion.
                 */
                $candidates = RoadmapTask::query()
                    ->where('roadmap_id', $roadmap->id)
                    ->whereIn('status', [
                        TicketStatus::Ready->value,
                        TicketStatus::ChangesRequested->value,
                    ])
                    ->with([
                        'roadmap',
                        'dependencies.dependsOn',
                    ])
                    ->get();

                if ($candidates->isEmpty()) {
                    return TicketSelectionResult::noWorkableTicket();
                }

                /*
                 * Normalize the plucked identifiers into a PHPStan-compatible
                 * list while preserving the active-lease exclusion behavior.
                 */
                $activeLeaseTaskIds = array_values(
                    TicketExecutionLease::query()
                        ->active()
                        ->where('project_id', $project->id)
                        ->pluck('roadmap_task_id')
                        ->map(static fn(mixed $id): int => (int) $id)
                        ->all(),
                );

                $rankedCandidates = $this->rankEligibleCandidates(
                    candidates: $candidates,
                    activeLeaseTaskIds: $activeLeaseTaskIds,
                    project: $project,
                    policyFacts: $policyFacts,
                );

                foreach ($rankedCandidates as $rankedCandidate) {
                    /*
                     * A competing selector already holding this row is skipped.
                     * The next ranked candidate may then be considered.
                     */
                    /** @var RoadmapTask|null $lockedTicket */
                    $lockedTicket = RoadmapTask::query()
                        ->where('roadmap_id', $roadmap->id)
                        ->where(
                            'stable_id',
                            $rankedCandidate->ticketId,
                        )
                        ->lock('for update skip locked')
                        ->first();

                    if ($lockedTicket === null) {
                        continue;
                    }

                    /*
                     * Re-check the active lease after obtaining the row lock.
                     * The partial unique index remains the final defense.
                     */
                    if (
                        TicketExecutionLease::query()
                        ->active()
                        ->where(
                            'roadmap_task_id',
                            $lockedTicket->id,
                        )
                        ->exists()
                    ) {
                        continue;
                    }

                    /*
                     * Dependency statuses are re-read under shared locks so
                     * their completion state cannot change before commit.
                     */
                    $dependencyStatuses =
                        $this->lockedDependencyStatuses($lockedTicket);

                    $approvalGranted = $policyFacts
                        ->approvalGrantedFor($lockedTicket);

                    /*
                     * Re-run eligibility using current locked database facts.
                     * The earlier candidate pass is only an optimization.
                     */
                    $eligibility = $this->eligibility->evaluate(
                        $this->eligibilityContext(
                            ticket: $lockedTicket,
                            dependencyStatuses: $dependencyStatuses,
                            approvalGranted: $approvalGranted,
                            project: $project,
                            policyFacts: $policyFacts,
                        ),
                    );

                    if (! $eligibility->isEligible()) {
                        continue;
                    }

                    $acquiredAt = CarbonImmutable::now();

                    $lease = TicketExecutionLease::query()->create([
                        'project_id' => $project->id,
                        'roadmap_task_id' => $lockedTicket->id,
                        'execution_id' => $execution->id,
                        'owner' => $request->owner,
                        'acquired_at' => $acquiredAt,
                        'expires_at' => $acquiredAt->addSeconds(
                            $request->leaseDurationSeconds,
                        ),
                        'heartbeat_at' => $acquiredAt,
                    ]);

                    $lockedTicket->setRelation('roadmap', $roadmap);

                    /*
                     * Event, audit, and lease records commit or roll back as one
                     * atomic operation.
                     */
                    $this->events->acquired(
                        project: $project,
                        ticket: $lockedTicket,
                        lease: $lease,
                        execution: $execution,
                    );

                    return TicketSelectionResult::selected(
                        ticket: $lockedTicket,
                        lease: $lease,
                    );
                }

                return TicketSelectionResult::noWorkableTicket();
            },
            attempts: 3,
        );
    }

    /**
     * Fail closed before replay or selection when execution cannot start work.
     */
    private function validateExecution(Execution $execution): void
    {
        if (
            ! ExecutionCapability::DevelopmentExecute->accepts(
                $execution->capability,
            )
        ) {
            throw new LogicException(
                'Execution does not support development work.',
            );
        }

        if ($execution->status !== ExecutionStatus::Queued) {
            throw new \LogicException(
                'Execution lifecycle does not permit ticket selection.',
            );
        }

        if ($execution->cancel_requested_at !== null) {
            throw new \LogicException(
                'Cancelled execution cannot select a ticket.',
            );
        }

        if ($execution->project_context_snapshot_id === null) {
            throw new \LogicException(
                'Execution has no immutable project context lineage.',
            );
        }
    }

    /**
     * Evaluate the initial candidate snapshot and rank only eligible tickets.
     *
     * @param  Collection<int, RoadmapTask>  $candidates
     * @param  list<int>  $activeLeaseTaskIds
     * @return list<TicketRankingContext>
     */
    private function rankEligibleCandidates(
        Collection $candidates,
        array $activeLeaseTaskIds,
        Project $project,
        TicketExecutionPolicyFacts $policyFacts,
    ): array {
        $activeLeaseLookup = array_fill_keys(
            $activeLeaseTaskIds,
            true,
        );

        $rankingContexts = [];

        foreach ($candidates as $candidate) {
            if (isset($activeLeaseLookup[$candidate->id])) {
                continue;
            }

            /*
             * Normalize dependency statuses into a sequential list for the
             * eligibility contract.
             */
            $dependencyStatuses = array_values(
                $candidate->dependencies
                    ->map(
                        static fn(
                            TaskDependency $dependency,
                        ): TicketStatus => $dependency->dependsOn->status,
                    )
                    ->all(),
            );

            $approvalGranted = $policyFacts
                ->approvalGrantedFor($candidate);

            $eligibility = $this->eligibility->evaluate(
                $this->eligibilityContext(
                    ticket: $candidate,
                    dependencyStatuses: $dependencyStatuses,
                    approvalGranted: $approvalGranted,
                    project: $project,
                    policyFacts: $policyFacts,
                ),
            );

            if (! $eligibility->isEligible()) {
                continue;
            }

            $rankingContexts[] = $this->rankingContext($candidate);
        }

        return $this->ranker->rank($rankingContexts);
    }

    /**
     * Create the eligibility snapshot from authoritative application facts.
     *
     * @param  list<TicketStatus>  $dependencyStatuses
     */
    private function eligibilityContext(
        RoadmapTask $ticket,
        array $dependencyStatuses,
        bool $approvalGranted,
        Project $project,
        TicketExecutionPolicyFacts $policyFacts,
    ): TicketEligibilityContext {
        return new TicketEligibilityContext(
            status: $ticket->status,
            changesRequestedApproved: $approvalGranted,
            hardDependencyStatuses: $dependencyStatuses,
            hasUnresolvedBlocker: $this->hasUnresolvedBlocker($ticket),
            approvalRequired: $ticket->human_approval_required,
            approvalGranted: $approvalGranted,
            projectStatus: $project->status,
            providerSupportsExecution: $policyFacts->providerSupportsExecution,
            budgetPermitsExecution: $policyFacts->budgetPermitsExecution,
            attemptCount: $policyFacts->attemptCount,
            retryLimit: $policyFacts->retryLimit,
        );
    }

    /**
     * Create the deterministic ranking snapshot for one eligible ticket.
     */
    private function rankingContext(
        RoadmapTask $ticket,
    ): TicketRankingContext {
        /*
         * status_changed_at is an authoritative non-null fallback when the
         * ticket does not have a dedicated ready_at timestamp.
         *
         * The approved MVP roadmap stores one authoritative position. It is
         * intentionally used for both approved roadmap order and explicit
         * sequence instead of introducing a redundant sequencing column.
         */
        $readyAt = $ticket->ready_at
            ?? $ticket->status_changed_at;

        return new TicketRankingContext(
            ticketId: $ticket->stable_id,
            roadmapOrder: $ticket->position,
            isCriticalPath: $ticket->is_critical_path,
            criticalPathRank: $ticket->critical_path_rank ?? 0,
            priority: strtolower($ticket->priority),
            explicitSequence: $ticket->position,
            riskPolicyRank: $this->riskPolicyRank($ticket->risk),
            readyAt: $readyAt->toDateTimeImmutable(),
            estimatedEffort: $ticket->estimated_complexity,
        );
    }

    /**
     * Lock and return current hard-dependency statuses before lease creation.
     *
     * @return list<TicketStatus>
     */
    private function lockedDependencyStatuses(
        RoadmapTask $ticket,
    ): array {
        /*
         * Normalize the dependency identifiers before passing them to whereKey.
         */
        $dependencyIds = array_values(
            TaskDependency::query()
                ->where('roadmap_task_id', $ticket->id)
                ->orderBy('depends_on_task_id')
                ->pluck('depends_on_task_id')
                ->map(static fn(mixed $id): int => (int) $id)
                ->all(),
        );

        if ($dependencyIds === []) {
            return [];
        }

        /*
         * Return a sequential list matching the eligibility context contract.
         */
        return array_values(
            RoadmapTask::query()
                ->whereKey($dependencyIds)
                ->orderBy('id')
                ->lock('for share')
                ->pluck('status')
                ->map(
                    static fn(
                        mixed $status,
                    ): TicketStatus => TicketStatus::from(
                        (string) $status,
                    ),
                )
                ->all(),
        );
    }

    /**
     * Detect unresolved blocker truth without trusting desired status alone.
     */
    private function hasUnresolvedBlocker(
        RoadmapTask $ticket,
    ): bool {
        if ($ticket->status === TicketStatus::Blocked) {
            return true;
        }

        foreach (
            [$ticket->reported_state, $ticket->observed_state] as $state
        ) {
            if (
                is_string($state)
                && strtolower(trim($state)) === 'blocked'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert persisted risk into the approved low-to-high policy order.
     */
    private function riskPolicyRank(string $risk): int
    {
        return match (strtolower($risk)) {
            'low' => 0,
            'medium' => 1,
            'high' => 2,
            'critical' => 3,
            default => 99,
        };
    }
}
