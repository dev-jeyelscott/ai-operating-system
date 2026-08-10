<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Models\OfficeProjection;
use App\Models\OutboxMessage;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Builds and persists the authoritative office projection for one project.
 */
final readonly class BuildOfficeProjection
{
    private const int SCHEMA_VERSION = 1;

    /**
     * Inject the operations model, provider activity reader, and room resolver.
     */
    public function __construct(
        private GetProjectOperationsReadModel $operations,
        private GetProjectProviderOfficeActivity $providerActivity,
        private ResolveOfficeAgentRoom $agentRooms,
    ) {}

    /**
     * Rebuild one tenant-scoped office projection from durable application state.
     */
    public function handle(
        int $organizationId,
        int $projectId,
        bool $rebuilt = false,
    ): OfficeProjection {
        return DB::transaction(
            function () use (
                $organizationId,
                $projectId,
                $rebuilt,
            ): OfficeProjection {
                $project = Project::query()
                    ->forOrganization($organizationId)
                    ->whereKey($projectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Read the durable checkpoint before the aggregate snapshot.
                 * Under PostgreSQL READ COMMITTED this is conservative: the
                 * projected state may include a newer commit, but the checkpoint
                 * can never claim an event that the snapshot read missed.
                 */
                $checkpoint = OutboxMessage::query()
                    ->where('organization_id', $organizationId)
                    ->where('project_id', $project->id)
                    ->latest('sequence')
                    ->first([
                        'sequence',
                        'event_id',
                    ]);

                $operations = $this->operations->handle(
                    organizationId: $organizationId,
                    projectId: $project->id,
                );

                $state = $this->buildState(
                    operations: $operations,
                    organizationId: $organizationId,
                    projectId: $project->id,
                );

                $fingerprint = hash(
                    'sha256',
                    json_encode($state, JSON_THROW_ON_ERROR),
                );

                $projection = OfficeProjection::query()
                    ->forOrganization($organizationId)
                    ->forProject($project->id)
                    ->lockForUpdate()
                    ->first();

                $lastEventSequence = (int) ($checkpoint->sequence ?? 0);
                $lastEventId = $checkpoint?->event_id;

                if (
                    $projection instanceof OfficeProjection
                    && $projection->schema_version === self::SCHEMA_VERSION
                    && $projection->fingerprint === $fingerprint
                    && $projection->last_event_sequence === $lastEventSequence
                    && ! $rebuilt
                ) {
                    return $projection;
                }

                $projection ??= new OfficeProjection;

                $attributes = [
                    'organization_id' => $organizationId,
                    'project_id' => $project->id,
                    'schema_version' => self::SCHEMA_VERSION,
                    'last_event_sequence' => $lastEventSequence,
                    'last_event_id' => $lastEventId,
                    'fingerprint' => $fingerprint,
                    'state' => $state,
                    'projected_at' => now(),
                ];

                if ($rebuilt) {
                    $attributes['rebuilt_at'] = now();
                }

                $projection->forceFill($attributes)->save();

                return $projection->refresh();
            },
            attempts: 3,
        );
    }

    /**
     * Transform operations state into the durable office projection contract.
     *
     * @param  array<string, mixed>  $operations
     * @return array<string, mixed>
     */
    private function buildState(
        array $operations,
        int $organizationId,
        int $projectId,
    ): array {
        $operationAgents = $this->list(
            $operations['agents'] ?? [],
        );

        $executionIds = array_values(array_filter(array_map(
            fn (array $agent): string => $this->string(
                $agent['id'] ?? '',
            ),
            $operationAgents,
        )));

        $providerActivity = $this->providerActivity->handle(
            organizationId: $organizationId,
            projectId: $projectId,
            executionIds: $executionIds,
        );

        $providerAgents = $providerActivity['agents'];

        $agents = $this->projectAgents(
            agents: $operationAgents,
            providerAgents: $providerAgents,
        );

        $layers = $this->indexByKey(
            $this->list($operations['layers'] ?? []),
        );

        $blockers = $this->list(
            $operations['blockers'] ?? [],
        );

        $approvals = $this->list(
            $operations['approvals'] ?? [],
        );

        $retries = $this->list(
            $operations['retries'] ?? [],
        );

        $decisions = $this->list(
            $operations['decisions'] ?? [],
        );

        $tickets = $this->list(
            $operations['tickets'] ?? [],
        );

        return [
            'project' => $this->map(
                $operations['project'] ?? [],
            ),
            'workflow' => $this->nullableMap(
                $operations['workflow'] ?? null,
            ),
            'roadmap' => $this->nullableMap(
                $operations['roadmap'] ?? null,
            ),
            'summary' => $this->map(
                $operations['summary'] ?? [],
            ),
            'rooms' => $this->buildRooms(
                agents: $agents,
                layers: $layers,
                tickets: $tickets,
                blockers: $blockers,
                approvals: $approvals,
                retries: $retries,
            ),
            'agents' => $agents,
            'activity' => $providerActivity['activity'],
            'indicators' => $this->buildIndicators(
                blockers: $blockers,
                approvals: $approvals,
                retries: $retries,
                decisions: $decisions,
            ),
            'simulation' => $this->buildSimulationState(
                agents: $agents,
                decisions: $decisions,
            ),
        ];
    }

    /**
     * Project operational executions into state-aware logical office agents.
     *
     * @param  list<array<string, mixed>>  $agents
     * @param  array<string, array<string, mixed>>  $providerAgents
     * @return list<array<string, mixed>>
     */
    private function projectAgents(
        array $agents,
        array $providerAgents,
    ): array {
        return array_map(
            function (array $agent) use ($providerAgents): array {
                $id = $this->string(
                    $agent['id'] ?? '',
                );

                $provider = $this->map(
                    $providerAgents[$id] ?? [],
                );

                $layer = $this->string(
                    $agent['layer'] ?? 'operations',
                    'operations',
                );

                $workflowState = $this->string(
                    $agent['state'] ?? 'queued',
                    'queued',
                );

                $officeState = $this->officeState(
                    layer: $layer,
                    workflowState: $workflowState,
                    provider: $provider,
                );

                $providerName = is_string(
                    $provider['provider'] ?? null,
                )
                    ? $provider['provider']
                    : (
                        is_string($agent['provider'] ?? null)
                        ? $agent['provider']
                        : null
                    );

                return [
                    'id' => $id,
                    'role' => $this->string(
                        $agent['role'] ?? 'unknown',
                        'unknown',
                    ),
                    'layer' => $layer,
                    'room' => $this->agentRooms->handle(
                        layer: $layer,
                        officeState: $officeState,
                    ),
                    'capability' => $this->string(
                        $agent['capability'] ?? '',
                    ),
                    'workflowState' => $workflowState,
                    'officeState' => $officeState,
                    'currentAction' => $this->currentAction(
                        officeState: $officeState,
                        provider: $provider,
                    ),
                    'active' => (bool) (
                        $agent['active'] ?? false
                    ),
                    'provider' => $providerName,
                    'model' => is_string(
                        $provider['model'] ?? null,
                    )
                        ? $provider['model']
                        : null,
                    'providerState' => is_string(
                        $provider['providerState'] ?? null,
                    )
                        ? $provider['providerState']
                        : null,
                    'providerPhase' => is_string(
                        $provider['providerPhase'] ?? null,
                    )
                        ? $provider['providerPhase']
                        : null,
                    'providerSequence' => (int) (
                        $provider['providerSequence'] ?? 0
                    ),
                    'lastProviderMessageAt' => is_string(
                        $provider['lastProviderMessageAt'] ?? null,
                    )
                        ? $provider['lastProviderMessageAt']
                        : null,
                    'requestedReasoning' => $this->string(
                        $agent['requestedReasoning'] ?? '',
                    ),
                    'effectiveReasoning' => is_string(
                        $agent['effectiveReasoning'] ?? null,
                    )
                        ? $agent['effectiveReasoning']
                        : null,
                    'ticketId' => is_string(
                        $agent['ticketId'] ?? null,
                    )
                        ? $agent['ticketId']
                        : null,
                    'attemptCount' => (int) (
                        $agent['attemptCount'] ?? 0
                    ),
                    'retryLimit' => (int) (
                        $agent['retryLimit'] ?? 0
                    ),
                    'nextAttemptAt' => $agent['nextAttemptAt'] ?? null,
                    'startedAt' => $agent['startedAt'] ?? null,
                    'finishedAt' => $agent['finishedAt'] ?? null,
                    'elapsedSeconds' => is_int(
                        $provider['elapsedSeconds'] ?? null,
                    )
                        ? $provider['elapsedSeconds']
                        : null,
                    'estimatedCost' => is_string(
                        $provider['estimatedCost'] ?? null,
                    )
                        ? $provider['estimatedCost']
                        : null,
                    'actualCost' => is_string(
                        $provider['actualCost'] ?? null,
                    )
                        ? $provider['actualCost']
                        : null,
                    'costCurrency' => is_string(
                        $provider['costCurrency'] ?? null,
                    )
                        ? $provider['costCurrency']
                        : null,
                    'confidence' => is_string(
                        $provider['confidence'] ?? null,
                    )
                        ? $provider['confidence']
                        : null,
                    'actualState' => is_string(
                        $provider['actualState'] ?? null,
                    )
                        ? $provider['actualState']
                        : null,
                    'approvalRequired' => (bool) (
                        $provider['approvalRequired'] ?? false
                    ),
                    'approvalSummary' => is_string(
                        $provider['approvalSummary'] ?? null,
                    )
                        ? $provider['approvalSummary']
                        : null,
                    'approvalUrl' => is_string(
                        $provider['approvalUrl'] ?? null,
                    )
                        ? $provider['approvalUrl']
                        : null,
                    'recoveryRequired' => (bool) (
                        $provider['recoveryRequired'] ?? false
                    ),
                    'diagnosticCode' => is_string(
                        $provider['diagnosticCode'] ?? null,
                    )
                        ? $provider['diagnosticCode']
                        : null,
                    'diagnosticMessage' => is_string(
                        $provider['diagnosticMessage'] ?? null,
                    )
                        ? $provider['diagnosticMessage']
                        : null,
                    'contextUrl' => $this->string(
                        $agent['contextUrl'] ?? '',
                    ),
                ];
            },
            $agents,
        );
    }

    /**
     * Build stable room summaries from authoritative state-aware agent placement.
     *
     * @param  list<array<string, mixed>>  $agents
     * @param  array<string, array<string, mixed>>  $layers
     * @param  list<array<string, mixed>>  $tickets
     * @param  list<array<string, mixed>>  $blockers
     * @param  list<array<string, mixed>>  $approvals
     * @param  list<array<string, mixed>>  $retries
     * @return list<array<string, mixed>>
     */
    private function buildRooms(
        array $agents,
        array $layers,
        array $tickets,
        array $blockers,
        array $approvals,
        array $retries,
    ): array {
        $doneTickets = count(array_filter(
            $tickets,
            static fn (array $ticket): bool => in_array(
                $ticket['status'] ?? null,
                ['done', 'cancelled'],
                true,
            ),
        ));

        $approvalAgents = $this->agentsForRoom(
            agents: $agents,
            room: 'approval_room',
        );

        $operationsAgents = $this->agentsForRoom(
            agents: $agents,
            room: 'operations_area',
        );

        $archiveAgents = $this->agentsForRoom(
            agents: $agents,
            room: 'archive',
        );

        $hasBlockedAgent = array_any(
            $operationsAgents,
            static fn (array $agent): bool => in_array(
                $agent['officeState'] ?? null,
                ['blocked', 'failed'],
                true,
            ),
        );

        $hasRetryingAgent = array_any(
            $operationsAgents,
            static fn (array $agent): bool => ($agent['officeState'] ?? null)
                === 'retrying',
        );

        return [
            $this->room(
                key: 'lobby',
                label: 'Lobby',
                state: 'idle',
                agents: [],
                actionableCount: 0,
            ),
            $this->layerRoom(
                key: 'planning_room',
                label: 'Planning Room',
                layer: 'planning',
                agents: $agents,
                layers: $layers,
            ),
            $this->layerRoom(
                key: 'development_floor',
                label: 'Development Floor',
                layer: 'development',
                agents: $agents,
                layers: $layers,
            ),
            $this->layerRoom(
                key: 'qa_laboratory',
                label: 'QA Laboratory',
                layer: 'quality_assurance',
                agents: $agents,
                layers: $layers,
            ),
            $this->room(
                key: 'approval_room',
                label: 'Approval Room',
                state: $approvalAgents !== [] || $approvals !== []
                    ? 'waiting_for_human'
                    : 'idle',
                agents: $approvalAgents,
                actionableCount: count($approvals),
            ),
            $this->room(
                key: 'operations_area',
                label: 'Operations Area',
                state: $blockers !== [] || $hasBlockedAgent
                    ? 'blocked'
                    : (
                        $retries !== [] || $hasRetryingAgent
                        ? 'retrying'
                        : 'idle'
                    ),
                agents: $operationsAgents,
                actionableCount: count($blockers) + count($retries),
            ),
            $this->room(
                key: 'archive',
                label: 'Completed Work',
                state: $archiveAgents !== [] || $doneTickets > 0
                    ? 'completed'
                    : 'idle',
                agents: $archiveAgents,
                actionableCount: 0,
                completedItems: $doneTickets,
            ),
        ];
    }

    /**
     * Build one normal working room backed by an operational layer.
     *
     * Agents in Approval, Operations, or Completed Work are excluded because their
     * authoritative state has moved them out of their normal layer room.
     *
     * @param  list<array<string, mixed>>  $agents
     * @param  array<string, array<string, mixed>>  $layers
     * @return array<string, mixed>
     */
    private function layerRoom(
        string $key,
        string $label,
        string $layer,
        array $agents,
        array $layers,
    ): array {
        $layerAgents = $this->agentsForRoom(
            agents: $agents,
            room: $key,
        );

        $layerState = $this->string(
            $layers[$layer]['state'] ?? 'idle',
            'idle',
        );

        return $this->room(
            key: $key,
            label: $label,
            state: $layerAgents === []
                ? 'idle'
                : $this->officeLayerState($layerState),
            agents: $layerAgents,
            actionableCount: 0,
        );
    }

    /**
     * Build one normalized room record.
     *
     * @param  list<array<string, mixed>>  $agents
     * @return array<string, mixed>
     */
    private function room(
        string $key,
        string $label,
        string $state,
        array $agents,
        int $actionableCount,
        int $completedItems = 0,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'activeAgents' => count(array_filter(
                $agents,
                static fn (array $agent): bool => (bool) $agent['active'],
            )),
            'agentIds' => array_map(
                static fn (array $agent): string => (string) $agent['id'],
                $agents,
            ),
            'actionableCount' => $actionableCount,
            'completedItems' => $completedItems,
        ];
    }

    /**
     * Build the actionable office indicators shown by 3D and accessible clients.
     *
     * @param  list<array<string, mixed>>  $blockers
     * @param  list<array<string, mixed>>  $approvals
     * @param  list<array<string, mixed>>  $retries
     * @param  list<array<string, mixed>>  $decisions
     * @return list<array<string, mixed>>
     */
    private function buildIndicators(
        array $blockers,
        array $approvals,
        array $retries,
        array $decisions,
    ): array {
        return [
            $this->indicator(
                key: 'blockers',
                label: 'Blockers',
                count: count($blockers),
                severity: $blockers === [] ? 'none' : 'critical',
                contextUrl: $this->firstContextUrl($blockers),
            ),
            $this->indicator(
                key: 'approvals',
                label: 'Pending approvals',
                count: count($approvals),
                severity: $approvals === [] ? 'none' : 'warning',
                contextUrl: $this->firstContextUrl($approvals),
            ),
            $this->indicator(
                key: 'retries',
                label: 'Scheduled retries',
                count: count($retries),
                severity: $retries === [] ? 'none' : 'warning',
                contextUrl: $this->firstContextUrl($retries),
            ),
            $this->indicator(
                key: 'decisions',
                label: 'Recent decisions',
                count: count($decisions),
                severity: 'info',
                contextUrl: $this->firstContextUrl($decisions),
                actionable: false,
            ),
        ];
    }

    /**
     * Build one normalized actionable indicator.
     *
     * @return array<string, mixed>
     */
    private function indicator(
        string $key,
        string $label,
        int $count,
        string $severity,
        ?string $contextUrl,
        bool $actionable = true,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'severity' => $severity,
            'actionable' => $actionable && $count > 0,
            'contextUrl' => $contextUrl,
        ];
    }

    /**
     * Build explicit simulation labeling information.
     *
     * @param  list<array<string, mixed>>  $agents
     * @param  list<array<string, mixed>>  $decisions
     * @return array<string, mixed>
     */
    private function buildSimulationState(
        array $agents,
        array $decisions,
    ): array {
        $simulatedAgent = array_any(
            $agents,
            static fn (array $agent): bool => $agent['provider'] === 'simulation'
                || str_contains(
                    strtolower((string) $agent['capability']),
                    'simulation',
                ),
        );

        $simulatedDecision = array_any(
            $decisions,
            static fn (array $decision): bool => (bool) (
                $decision['simulated'] ?? false
            ),
        );

        $labelRequired = $simulatedAgent || $simulatedDecision;

        return [
            'labelRequired' => $labelRequired,
            'executionProvider' => $labelRequired
                ? 'simulation'
                : null,
            'actualState' => $labelRequired
                ? 'unverified'
                : 'observed',
        ];
    }

    /**
     * Resolve office state using deterministic workflow/provider precedence.
     *
     * Provider activity may refine a running execution but may never override
     * terminal workflow state, retry policy, blocking, or human approval gates.
     *
     * @param  array<string, mixed>  $provider
     */
    private function officeState(
        string $layer,
        string $workflowState,
        array $provider,
    ): string {
        $providerState = $this->string(
            $provider['providerState'] ?? '',
        );

        $providerPhase = $this->string(
            $provider['providerPhase'] ?? '',
        );

        $activityState = $this->string(
            $provider['latestActivityState'] ?? '',
        );

        if (
            $workflowState === 'failed'
            || $providerState === 'failed'
            || $activityState === 'failed'
        ) {
            return 'failed';
        }

        if (
            $workflowState === 'cancelled'
            || $providerState === 'cancelled'
            || $activityState === 'cancelled'
        ) {
            return 'cancelled';
        }

        /*
         * Workflow completion remains authoritative. Provider turn completion only
         * means the provider response is ready for deterministic validation.
         */
        if ($workflowState === 'completed') {
            return 'completed';
        }

        if ($workflowState === 'retry_scheduled') {
            return 'retrying';
        }

        if (
            $workflowState === 'blocked'
            || (bool) ($provider['recoveryRequired'] ?? false)
            || $providerState === 'lost'
        ) {
            return 'blocked';
        }

        if (
            $workflowState === 'waiting_for_approval'
            || (bool) ($provider['approvalRequired'] ?? false)
            || $providerPhase === 'approval_wait'
            || $activityState === 'waiting_for_approval'
        ) {
            return 'waiting_for_approval';
        }

        if (
            $workflowState === 'waiting_for_evidence'
            || $providerPhase === 'validation'
            || $activityState === 'validating'
        ) {
            return 'validating';
        }

        if ($workflowState === 'queued') {
            return 'selecting_ticket';
        }

        if ($workflowState !== 'running') {
            return 'idle';
        }

        if ($layer === 'planning') {
            if (
                in_array(
                    $activityState,
                    ['reading_documents', 'planning'],
                    true,
                )
            ) {
                return $activityState;
            }

            return match ($providerPhase) {
                'startup',
                'idle' => 'reading_documents',
                'turn' => 'planning',
                'validation' => 'validating',
                default => 'planning',
            };
        }

        return match ($layer) {
            'development' => 'implementing',
            'quality_assurance' => 'reviewing',
            default => 'validating',
        };
    }

    /**
     * Resolve safe current-action text without copying provider payloads.
     *
     * @param  array<string, mixed>  $provider
     */
    private function currentAction(
        string $officeState,
        array $provider,
    ): string {
        $providerActivityState = $this->string(
            $provider['latestActivityState'] ?? '',
        );

        $providerAction = $this->string(
            $provider['currentAction'] ?? '',
        );

        if (
            $providerAction !== ''
            && $providerActivityState === $officeState
        ) {
            return $providerAction;
        }

        return match ($officeState) {
            'selecting_ticket' => 'Selecting the next workable ticket',
            'reading_documents' => 'Reading approved planning context',
            'planning' => 'Planning project work',
            'waiting_for_approval' => 'Waiting for an authorized provider decision',
            'implementing' => 'Implementing the assigned ticket',
            'validating' => 'Validating evidence and workflow state',
            'reviewing' => 'Reviewing implementation evidence',
            'blocked' => 'Waiting for blocker remediation',
            'retrying' => 'Waiting for the next retry attempt',
            'waiting_for_human' => 'Waiting for an authorized decision',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'failed' => 'Failed',
            default => 'Idle',
        };
    }

    /**
     * Map a layer summary state to the office state vocabulary.
     */
    private function officeLayerState(string $layerState): string
    {
        return match ($layerState) {
            'working' => 'working',
            'queued' => 'queued',
            'blocked' => 'blocked',
            'retrying' => 'retrying',
            'waiting_for_human' => 'waiting_for_human',
            'waiting_for_evidence' => 'validating',
            'completed' => 'completed',
            default => 'idle',
        };
    }

    /**
     * Return agents assigned to one authoritative office room.
     *
     * Room assignment has already been resolved from the workflow layer and
     * authoritative office state by ResolveOfficeAgentRoom.
     *
     * @param  list<array<string, mixed>>  $agents
     * @return list<array<string, mixed>>
     */
    private function agentsForRoom(
        array $agents,
        string $room,
    ): array {
        return array_values(array_filter(
            $agents,
            static fn (array $agent): bool => ($agent['room'] ?? null)
                === $room,
        ));
    }

    /**
     * Index rows by their stable key field.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexByKey(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $key = $row['key'] ?? null;

            if (is_string($key) && $key !== '') {
                $indexed[$key] = $row;
            }
        }

        return $indexed;
    }

    /**
     * Return the first valid deep-link URL from a row list.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function firstContextUrl(array $rows): ?string
    {
        foreach ($rows as $row) {
            $contextUrl = $row['contextUrl'] ?? null;

            if (is_string($contextUrl) && $contextUrl !== '') {
                return $contextUrl;
            }
        }

        return null;
    }

    /**
     * Normalize a mixed value into an associative map.
     *
     * @return array<string, mixed>
     */
    private function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Normalize a nullable mixed value into an associative map.
     *
     * @return array<string, mixed>|null
     */
    private function nullableMap(mixed $value): ?array
    {
        return $value === null
            ? null
            : $this->map($value);
    }

    /**
     * Normalize a mixed value into a list of associative maps.
     *
     * @return list<array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $row): bool => is_array($row),
        ));
    }

    /**
     * Normalize a mixed value into a string.
     */
    private function string(
        mixed $value,
        string $fallback = '',
    ): string {
        return is_string($value) && $value !== ''
            ? $value
            : $fallback;
    }
}
