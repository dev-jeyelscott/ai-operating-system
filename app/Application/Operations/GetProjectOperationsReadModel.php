<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketStatus;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\MergeDecision;
use App\Models\Organization;
use App\Models\Project;
use App\Models\QaAssessment;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use App\Models\TicketExecutionLease;
use App\Models\WorkflowInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds the authoritative project operations query model.
 *
 * This service does not maintain another source of truth. It summarizes the
 * current workflow aggregates for AIOS-120 and the future office projection.
 */
final readonly class GetProjectOperationsReadModel
{
    private const int SCHEMA_VERSION = 1;

    private const int RECENT_TERMINAL_DAYS = 7;

    private const int MAX_EXECUTIONS = 100;

    private const int MAX_ASSESSMENTS = 25;

    private const int MAX_DECISIONS = 25;

    /**
     * Return the tenant-scoped operational state of one project.
     *
     * @return array<string, mixed>
     */
    public function handle(
        int $organizationId,
        int $projectId,
    ): array {
        $asOf = CarbonImmutable::now();

        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();

        $organization = Organization::query()
            ->whereKey($organizationId)
            ->firstOrFail();

        $workflow = WorkflowInstance::query()
            ->where('project_id', $project->id)
            ->latest('id')
            ->first();

        $roadmap = Roadmap::query()
            ->where('project_id', $project->id)
            ->where('status', 'approved')
            ->whereNotNull('approved_at')
            ->latest('revision')
            ->first();

        $tickets = $roadmap instanceof Roadmap
            ? RoadmapTask::query()
                ->where('roadmap_id', $roadmap->id)
                ->orderBy('position')
                ->orderBy('stable_id')
                ->get()
            : collect();

        $executions = $this->executions(
            projectId: $project->id,
            asOf: $asOf,
        );

        $latestAttempts = $this->latestAttempts(
            $executions->modelKeys(),
        );

        $activeLeases = TicketExecutionLease::query()
            ->where('project_id', $project->id)
            ->active()
            ->with('ticket')
            ->orderBy('acquired_at')
            ->get();

        $leasesByExecution = $activeLeases->keyBy('execution_id');
        $leasesByTicket = $activeLeases->keyBy('roadmap_task_id');

        $approvals = Approval::query()
            ->forProject($project->id)
            ->where('status', ApprovalStatus::Pending->value)
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get();

        $assessments = QaAssessment::query()
            ->forProject($project->id)
            ->with('ticket')
            ->latest('created_at')
            ->limit(self::MAX_ASSESSMENTS)
            ->get();

        $assessmentsByReviewExecution = $assessments
            ->keyBy('review_execution_id');

        $decisions = MergeDecision::query()
            ->forProject($project->id)
            ->latest('decided_at')
            ->limit(self::MAX_DECISIONS)
            ->get();

        $ticketsById = $tickets->keyBy('id');

        $agents = [];

        foreach ($executions as $execution) {
            if (
                ! is_string($execution->logical_role)
                || trim($execution->logical_role) === ''
            ) {
                continue;
            }

            $agents[] = $this->serializeAgent(
                organization: $organization,
                project: $project,
                roadmap: $roadmap,
                execution: $execution,
                attempt: $latestAttempts->get($execution->id),
                lease: $leasesByExecution->get($execution->id),
                assessment: $assessmentsByReviewExecution->get(
                    $execution->id,
                ),
            );
        }

        $ticketRows = [];

        foreach ($tickets as $ticket) {
            $ticketRows[] = $this->serializeTicket(
                organization: $organization,
                project: $project,
                roadmap: $roadmap,
                ticket: $ticket,
                lease: $leasesByTicket->get($ticket->id),
            );
        }

        $blockers = $this->buildBlockers(
            organization: $organization,
            project: $project,
            roadmap: $roadmap,
            tickets: $tickets,
            executions: $executions,
        );

        $approvalRows = [];

        foreach ($approvals as $approval) {
            $approvalRows[] = $this->serializeApproval(
                organization: $organization,
                project: $project,
                approval: $approval,
            );
        }

        $retryRows = [];

        foreach ($executions as $execution) {
            if ($execution->status !== ExecutionStatus::RetryScheduled) {
                continue;
            }

            $retryRows[] = $this->serializeRetry(
                organization: $organization,
                project: $project,
                execution: $execution,
            );
        }

        $decisionRows = [];

        foreach ($decisions as $decision) {
            $decisionRows[] = $this->serializeDecision(
                organization: $organization,
                project: $project,
                decision: $decision,
                ticket: $ticketsById->get(
                    $decision->roadmap_task_id,
                ),
            );
        }

        $layers = $this->buildLayers($agents);

        $core = [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status->value,
                'archived' => $project->isArchived(),
            ],

            'workflow' => $workflow instanceof WorkflowInstance
                ? [
                    'id' => $workflow->id,
                    'state' => $workflow->current_state,
                    'transitionSequence' => $workflow
                        ->transition_sequence,
                    'completedAt' => $workflow
                        ->completed_at
                        ?->toIso8601String(),
                ]
                : null,

            'roadmap' => $roadmap instanceof Roadmap
                ? [
                    'id' => $roadmap->id,
                    'revision' => $roadmap->revision,
                    'status' => $roadmap->status,
                    'readiness' => $roadmap->readiness,
                    'approvedAt' => $roadmap
                        ->approved_at
                        ?->toIso8601String(),
                ]
                : null,

            'summary' => [
                'activeAgents' => count(array_filter(
                    $agents,
                    static fn (array $agent): bool => $agent['active'],
                )),
                'ticketsTotal' => count($ticketRows),
                'ticketsByStatus' => $this->ticketCounts($tickets),
                'blockers' => count($blockers),
                'pendingApprovals' => count($approvalRows),
                'retriesScheduled' => count($retryRows),
                'recentDecisions' => count($decisionRows),
            ],

            'layers' => $layers,
            'agents' => $agents,
            'tickets' => $ticketRows,
            'blockers' => $blockers,
            'approvals' => $approvalRows,
            'retries' => $retryRows,
            'decisions' => $decisionRows,
        ];

        return [
            'metadata' => [
                'schemaVersion' => self::SCHEMA_VERSION,
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
     * Load all active executions plus a bounded recent terminal history.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Execution>
     */
    private function executions(
        int $projectId,
        CarbonImmutable $asOf,
    ) {
        $activeStatuses = [
            ExecutionStatus::Queued->value,
            ExecutionStatus::Running->value,
            ExecutionStatus::WaitingForApproval->value,
            ExecutionStatus::WaitingForEvidence->value,
            ExecutionStatus::Blocked->value,
            ExecutionStatus::RetryScheduled->value,
        ];

        return Execution::query()
            ->forProject($projectId)
            ->where(
                static function (Builder $query) use (
                    $activeStatuses,
                    $asOf,
                ): void {
                    $query
                        ->whereIn('status', $activeStatuses)
                        ->orWhere(
                            'created_at',
                            '>=',
                            $asOf->subDays(
                                self::RECENT_TERMINAL_DAYS,
                            ),
                        );
                },
            )
            ->latest('created_at')
            ->limit(self::MAX_EXECUTIONS)
            ->get();
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
     * Serialize one logical execution as an operational agent.
     *
     * @return array<string, mixed>
     */
    private function serializeAgent(
        Organization $organization,
        Project $project,
        ?Roadmap $roadmap,
        Execution $execution,
        mixed $attempt,
        mixed $lease,
        mixed $assessment,
    ): array {
        $attempt = $attempt instanceof ExecutionAttempt
            ? $attempt
            : null;

        $lease = $lease instanceof TicketExecutionLease
            ? $lease
            : null;

        $assessment = $assessment instanceof QaAssessment
            ? $assessment
            : null;

        return [
            'id' => $execution->id,
            'role' => $execution->logical_role,
            'layer' => $this->layerForCapability(
                $execution->capability,
            ),
            'capability' => $execution->capability,
            'state' => $execution->status->value,
            'active' => ! $execution->status->isTerminal(),
            'provider' => $attempt?->execution_provider,
            'requestedReasoning' => $execution
                ->requested_reasoning_level
                ->value,
            'effectiveReasoning' => $attempt
                ?->effective_reasoning_level
                ->value,
            'ticketId' => $lease?->ticket?->stable_id,
            'attemptCount' => $execution->attempt_count,
            'retryLimit' => $execution->retry_limit,
            'nextAttemptAt' => $execution
                ->next_attempt_at
                ?->toIso8601String(),
            'startedAt' => $execution
                ->started_at
                ?->toIso8601String(),
            'finishedAt' => $execution
                ->finished_at
                ?->toIso8601String(),
            'contextUrl' => $this->executionContextUrl(
                organization: $organization,
                project: $project,
                roadmap: $roadmap,
                execution: $execution,
                assessment: $assessment,
            ),
        ];
    }

    /**
     * Serialize one authoritative ticket in roadmap order.
     *
     * @return array<string, mixed>
     */
    private function serializeTicket(
        Organization $organization,
        Project $project,
        ?Roadmap $roadmap,
        RoadmapTask $ticket,
        mixed $lease,
    ): array {
        $lease = $lease instanceof TicketExecutionLease
            ? $lease
            : null;

        $blocked = $this->ticketIsBlocked($ticket);

        return [
            'id' => $ticket->stable_id,
            'databaseId' => $ticket->id,
            'title' => $ticket->title,
            'logicalAgent' => $ticket->logical_agent,
            'status' => $ticket->status->value,
            'desiredState' => $ticket->desired_state->value,
            'reportedState' => $ticket->reported_state,
            'observedState' => $ticket->observed_state,
            'actualState' => $ticket->actual_state->value,
            'priority' => strtolower($ticket->priority),
            'risk' => strtolower($ticket->risk),
            'position' => $ticket->position,
            'blocked' => $blocked,
            'activeLease' => $lease !== null,
            'leaseExpiresAt' => $lease
                ?->expires_at
                ->toIso8601String(),
            'contextUrl' => $roadmap instanceof Roadmap
                ? route(
                    'organizations.projects.roadmaps.tasks.show',
                    [
                        'organization' => $organization,
                        'project' => $project,
                        'roadmap' => $roadmap,
                        'task' => $ticket,
                    ],
                    false,
                )
                : route(
                    'organizations.projects.development.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                    false,
                ),
        ];
    }

    /**
     * Build project ticket and execution blockers.
     *
     * @param  Collection<int, RoadmapTask>  $tickets
     * @param  Collection<int, Execution>  $executions
     * @return list<array<string, mixed>>
     */
    private function buildBlockers(
        Organization $organization,
        Project $project,
        ?Roadmap $roadmap,
        Collection $tickets,
        Collection $executions,
    ): array {
        $blockers = [];

        foreach ($tickets as $ticket) {
            if (! $this->ticketIsBlocked($ticket)) {
                continue;
            }

            $blockers[] = [
                'type' => 'ticket',
                'id' => $ticket->stable_id,
                'title' => $ticket->title,
                'message' => 'The ticket is blocked by authoritative workflow state.',
                'contextUrl' => $roadmap instanceof Roadmap
                    ? route(
                        'organizations.projects.roadmaps.tasks.show',
                        [
                            'organization' => $organization,
                            'project' => $project,
                            'roadmap' => $roadmap,
                            'task' => $ticket,
                        ],
                        false,
                    ).'#blocker'
                    : route(
                        'organizations.projects.development.index',
                        [
                            'organization' => $organization,
                            'project' => $project,
                        ],
                        false,
                    ),
            ];
        }

        foreach ($executions as $execution) {
            if ($execution->status !== ExecutionStatus::Blocked) {
                continue;
            }

            $blockers[] = [
                'type' => 'execution',
                'id' => $execution->id,
                'title' => sprintf(
                    '%s execution blocked',
                    $execution->logical_role ?? $execution->capability,
                ),
                'message' => 'The execution requires remediation or a human decision.',
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

        return $blockers;
    }

    /**
     * Serialize one pending approval.
     *
     * @return array<string, mixed>
     */
    private function serializeApproval(
        Organization $organization,
        Project $project,
        Approval $approval,
    ): array {
        return [
            'id' => $approval->id,
            'type' => $approval->type->value,
            'status' => $approval->status->value,
            'executionId' => $approval->execution_id,
            'requestedAt' => $approval
                ->requested_at
                ->toIso8601String(),
            'expiresAt' => $approval
                ->expires_at
                ?->toIso8601String(),
            'contextUrl' => route(
                'organizations.projects.audit.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'approval' => $approval->id,
                ],
                false,
            ).'#approval-'.$approval->id,
        ];
    }

    /**
     * Serialize one execution waiting for an automatic retry.
     *
     * @return array<string, mixed>
     */
    private function serializeRetry(
        Organization $organization,
        Project $project,
        Execution $execution,
    ): array {
        return [
            'executionId' => $execution->id,
            'capability' => $execution->capability,
            'logicalRole' => $execution->logical_role,
            'attemptCount' => $execution->attempt_count,
            'retryLimit' => $execution->retry_limit,
            'nextAttemptAt' => $execution
                ->next_attempt_at
                ?->toIso8601String(),
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

    /**
     * Serialize one recent merge decision.
     *
     * @return array<string, mixed>
     */
    private function serializeDecision(
        Organization $organization,
        Project $project,
        MergeDecision $decision,
        mixed $ticket,
    ): array {
        $ticket = $ticket instanceof RoadmapTask
            ? $ticket
            : null;

        return [
            'id' => $decision->id,
            'assessmentId' => $decision->qa_assessment_id,
            'ticketId' => $ticket?->stable_id,
            'action' => $decision->action->value,
            'reason' => $decision->reason,
            'simulated' => $decision->simulated,
            'actualState' => $decision->actual_state,
            'decidedAt' => $decision
                ->decided_at
                ->toIso8601String(),
            'contextUrl' => route(
                'organizations.projects.quality-assurance.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'assessment' => $decision->qa_assessment_id,
                ],
                false,
            ).'#decision-center',
        ];
    }

    /**
     * Group logical agents into stable operational layers.
     *
     * @param  list<array<string, mixed>>  $agents
     * @return list<array<string, mixed>>
     */
    private function buildLayers(array $agents): array
    {
        $definitions = [
            'planning' => 'Planning',
            'development' => 'Development',
            'quality_assurance' => 'Quality Assurance',
            'operations' => 'Operations',
        ];

        $layers = [];

        foreach ($definitions as $key => $label) {
            $layerAgents = array_values(array_filter(
                $agents,
                static fn (array $agent): bool => $agent['layer'] === $key,
            ));

            $layers[] = [
                'key' => $key,
                'label' => $label,
                'state' => $this->layerState($layerAgents),
                'activeAgents' => count(array_filter(
                    $layerAgents,
                    static fn (array $agent): bool => $agent['active'],
                )),
                'agents' => array_map(
                    static fn (array $agent): string => $agent['id'],
                    $layerAgents,
                ),
            ];
        }

        return $layers;
    }

    /**
     * Resolve one layer's display state from authoritative execution states.
     *
     * @param  list<array<string, mixed>>  $agents
     */
    private function layerState(array $agents): string
    {
        if ($agents === []) {
            return 'idle';
        }

        $states = array_column($agents, 'state');

        foreach ([
            ExecutionStatus::Blocked->value => 'blocked',
            ExecutionStatus::WaitingForApproval->value => 'waiting_for_human',
            ExecutionStatus::WaitingForEvidence->value => 'waiting_for_evidence',
            ExecutionStatus::RetryScheduled->value => 'retrying',
            ExecutionStatus::Running->value => 'working',
            ExecutionStatus::Queued->value => 'queued',
        ] as $executionState => $layerState) {
            if (in_array($executionState, $states, true)) {
                return $layerState;
            }
        }

        return 'completed';
    }

    /**
     * Map an execution capability to its operational layer.
     */
    private function layerForCapability(string $capability): string
    {
        $capability = strtolower($capability);

        if (str_contains($capability, 'planning')) {
            return 'planning';
        }

        if (
            str_contains($capability, 'development')
            || str_contains($capability, 'implementation')
        ) {
            return 'development';
        }

        if (
            str_contains($capability, 'quality_assurance')
            || str_contains($capability, 'review')
            || str_contains($capability, 'merge')
        ) {
            return 'quality_assurance';
        }

        return 'operations';
    }

    /**
     * Resolve the layer-specific inspector URL for an execution.
     */
    private function executionContextUrl(
        Organization $organization,
        Project $project,
        ?Roadmap $roadmap,
        Execution $execution,
        ?QaAssessment $assessment,
    ): string {
        $layer = $this->layerForCapability($execution->capability);

        if (
            $layer === 'planning'
            && $roadmap instanceof Roadmap
            && $roadmap->planning_execution_id === $execution->id
        ) {
            return route(
                'organizations.projects.roadmaps.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'roadmap' => $roadmap,
                ],
                false,
            );
        }

        if ($layer === 'development') {
            return route(
                'organizations.projects.development.executions.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'execution' => $execution,
                ],
                false,
            );
        }

        if ($layer === 'quality_assurance') {
            return route(
                'organizations.projects.quality-assurance.index',
                array_filter([
                    'organization' => $organization,
                    'project' => $project,
                    'assessment' => $assessment?->id,
                ]),
                false,
            ).'#assessment';
        }

        return route(
            'organizations.projects.audit.index',
            [
                'organization' => $organization,
                'project' => $project,
                'execution' => $execution->id,
            ],
            false,
        ).'#execution-'.$execution->id;
    }

    /**
     * Determine whether the authoritative or observed ticket state is blocked.
     */
    private function ticketIsBlocked(RoadmapTask $ticket): bool
    {
        if ($ticket->status === TicketStatus::Blocked) {
            return true;
        }

        $reported = strtolower(trim((string) $ticket->reported_state));
        $observed = strtolower(trim((string) $ticket->observed_state));

        return $reported === TicketStatus::Blocked->value
            || $observed === TicketStatus::Blocked->value;
    }

    /**
     * Count tickets by every canonical ticket state.
     *
     * @param  Collection<int, RoadmapTask>  $tickets
     * @return array<string, int>
     */
    private function ticketCounts(Collection $tickets): array
    {
        $counts = [];

        foreach (TicketStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        foreach ($tickets as $ticket) {
            $counts[$ticket->status->value]++;
        }

        return $counts;
    }
}
