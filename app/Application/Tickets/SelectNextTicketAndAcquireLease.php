<?php

declare(strict_types=1);

namespace App\Application\Tickets;

use App\Application\Tickets\Data\TicketEligibilityContext;
use App\Application\Tickets\Data\TicketRankingContext;
use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\Data\TicketSelectionResult;
use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Approvals\ApprovalType;
use App\Domain\Tickets\TicketStatus;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use App\Models\TaskDependency;
use App\Models\TicketExecutionLease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

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

                $activeLeaseTaskIds = TicketExecutionLease::query()
                    ->active()
                    ->where('project_id', $project->id)
                    ->pluck('roadmap_task_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                $approvedTicketReferences =
                    $this->approvedTicketReferences($project->id);

                $rankedCandidates = $this->rankEligibleCandidates(
                    candidates: $candidates,
                    activeLeaseTaskIds: $activeLeaseTaskIds,
                    approvedTicketReferences: $approvedTicketReferences,
                    project: $project,
                    execution: $execution,
                    request: $request,
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

                    $approvalGranted = $this->approvalGranted(
                        ticket: $lockedTicket,
                        approvedTicketReferences: $approvedTicketReferences,
                    );

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
                            execution: $execution,
                            request: $request,
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
     * Evaluate the initial candidate snapshot and rank only eligible tickets.
     *
     * @param  Collection<int, RoadmapTask>  $candidates
     * @param  list<int>  $activeLeaseTaskIds
     * @param  array{
     *     ids:array<int, true>,
     *     stable_ids:array<string, true>
     * }  $approvedTicketReferences
     * @return list<TicketRankingContext>
     */
    private function rankEligibleCandidates(
        Collection $candidates,
        array $activeLeaseTaskIds,
        array $approvedTicketReferences,
        Project $project,
        Execution $execution,
        TicketSelectionRequest $request,
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

            $dependencyStatuses = $candidate->dependencies
                ->map(
                    static fn (
                        TaskDependency $dependency,
                    ): TicketStatus => $dependency->dependsOn->status,
                )
                ->values()
                ->all();

            $approvalGranted = $this->approvalGranted(
                ticket: $candidate,
                approvedTicketReferences: $approvedTicketReferences,
            );

            $eligibility = $this->eligibility->evaluate(
                $this->eligibilityContext(
                    ticket: $candidate,
                    dependencyStatuses: $dependencyStatuses,
                    approvalGranted: $approvalGranted,
                    project: $project,
                    execution: $execution,
                    request: $request,
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
        Execution $execution,
        TicketSelectionRequest $request,
    ): TicketEligibilityContext {
        return new TicketEligibilityContext(
            status: $ticket->status,
            changesRequestedApproved: $approvalGranted,
            hardDependencyStatuses: $dependencyStatuses,
            hasUnresolvedBlocker: $this->hasUnresolvedBlocker($ticket),
            approvalRequired: $ticket->human_approval_required,
            approvalGranted: $approvalGranted,
            projectStatus: $project->status,
            providerSupportsExecution: $request->providerSupportsExecution,
            budgetPermitsExecution: $request->budgetPermitsExecution,
            attemptCount: $execution->attempt_count,
            retryLimit: $execution->retry_limit,
        );
    }

    /**
     * Create the deterministic ranking snapshot for one eligible ticket.
     */
    private function rankingContext(
        RoadmapTask $ticket,
    ): TicketRankingContext {
        $readyAt = $ticket->ready_at
            ?? $ticket->status_changed_at;

        if ($readyAt === null) {
            throw new LogicException(
                'Eligible ticket has no deterministic ready timestamp.',
            );
        }

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
        $dependencyIds = TaskDependency::query()
            ->where('roadmap_task_id', $ticket->id)
            ->orderBy('depends_on_task_id')
            ->pluck('depends_on_task_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($dependencyIds === []) {
            return [];
        }

        return RoadmapTask::query()
            ->whereKey($dependencyIds)
            ->orderBy('id')
            ->lock('for share')
            ->pluck('status')
            ->map(
                static fn (mixed $status): TicketStatus => TicketStatus::from((string) $status),
            )
            ->values()
            ->all();
    }

    /**
     * Return approved execution-approval references for this project.
     *
     * @return array{
     *     ids:array<int, true>,
     *     stable_ids:array<string, true>
     * }
     */
    private function approvedTicketReferences(
        int $projectId,
    ): array {
        $references = [
            'ids' => [],
            'stable_ids' => [],
        ];

        $approvals = Approval::query()
            ->forProject($projectId)
            ->where('type', ApprovalType::Execution->value)
            ->where('status', ApprovalStatus::Approved->value)
            ->get(['request_payload']);

        foreach ($approvals as $approval) {
            $payload = $approval->request_payload;

            $roadmapTaskId =
                $payload['roadmap_task_id'] ?? null;

            $ticketId =
                $payload['ticket_id'] ?? null;

            if (is_int($roadmapTaskId) && $roadmapTaskId > 0) {
                $references['ids'][$roadmapTaskId] = true;
            }

            if (
                is_string($ticketId)
                && $ticketId !== ''
                && trim($ticketId) === $ticketId
            ) {
                $references['stable_ids'][$ticketId] = true;
            }
        }

        return $references;
    }

    /**
     * Determine whether an approved execution decision references the ticket.
     *
     * @param  array{
     *     ids:array<int, true>,
     *     stable_ids:array<string, true>
     * }  $approvedTicketReferences
     */
    private function approvalGranted(
        RoadmapTask $ticket,
        array $approvedTicketReferences,
    ): bool {
        return isset(
            $approvedTicketReferences['ids'][$ticket->id],
        ) || isset(
            $approvedTicketReferences['stable_ids'][
                $ticket->stable_id
            ],
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
