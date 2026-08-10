<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Audit\AuditEventType;
use App\Models\CodexApprovalRequest;
use App\Models\ExecutionAttempt;
use App\Models\OutboxMessage;
use App\Models\ProviderSession;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds privacy-safe provider activity for the durable office projection.
 *
 * Raw provider payloads, prompts, commands, document bodies, temporary paths,
 * and encrypted approval context never leave the provider persistence boundary.
 */
final readonly class GetProjectProviderOfficeActivity
{
    private const int ACTIVITY_LIMIT = 30;

    /**
     * Defines provider lifecycle events meaningful enough for office activity.
     *
     * High-volume output chunks and transcript records are intentionally omitted.
     *
     * @var list<string>
     */
    private const array PROVIDER_EVENT_NAMES = [
        AuditEventType::ProviderSessionStarted->value,
        AuditEventType::ProviderThreadStarted->value,
        AuditEventType::ProviderTurnStarted->value,
        AuditEventType::ProviderItemStarted->value,
        AuditEventType::ProviderItemCompleted->value,
        AuditEventType::ProviderApprovalRequested->value,
        AuditEventType::ProviderApprovalResolved->value,
        AuditEventType::ProviderCommandRequested->value,
        AuditEventType::ProviderCommandCompleted->value,
        AuditEventType::ProviderTurnCompleted->value,
        AuditEventType::ProviderTurnFailed->value,
        AuditEventType::ProviderSessionCancelled->value,
    ];

    /**
     * Return provider metadata keyed by execution plus bounded activity.
     *
     * @param  list<string>  $executionIds
     * @return array{
     *     agents: array<string, array<string, mixed>>,
     *     activity: list<array<string, mixed>>
     * }
     */
    public function handle(
        int $organizationId,
        int $projectId,
        array $executionIds,
    ): array {
        $executionIds = array_values(array_unique(array_filter(
            $executionIds,
            static fn (string $executionId): bool => trim($executionId) !== '',
        )));

        if ($executionIds === []) {
            return [
                'agents' => [],
                'activity' => [],
            ];
        }

        $attempts = ExecutionAttempt::query()
            ->whereIn('execution_id', $executionIds)
            ->whereHas(
                'execution',
                static function (Builder $query) use ($projectId): void {
                    $query->where('project_id', $projectId);
                },
            )
            ->whereHas(
                'execution.project',
                static function (Builder $query) use ($organizationId): void {
                    $query->where('organization_id', $organizationId);
                },
            )
            ->orderByDesc('attempt_number')
            ->orderByDesc('id')
            ->get()
            ->unique('execution_id')
            ->keyBy('execution_id');

        $sessions = ProviderSession::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->whereIn('execution_id', $executionIds)
            ->orderByDesc('execution_attempt_id')
            ->orderByDesc('created_at')
            ->get()
            ->unique('execution_id')
            ->keyBy('execution_id');

        $approvals = CodexApprovalRequest::query()
            ->where('organization_id', $organizationId)
            ->whereIn('execution_id', $executionIds)
            ->with('approval')
            ->latest('created_at')
            ->get()
            ->filter(
                static fn (CodexApprovalRequest $request): bool => $request
                    ->approval
                    ->status === ApprovalStatus::Pending,
            )
            ->unique('execution_id')
            ->keyBy('execution_id');

        $activity = $this->activity(
            organizationId: $organizationId,
            projectId: $projectId,
            executionIds: $executionIds,
        );

        $latestActivity = [];

        foreach ($activity as $item) {
            $executionId = $item['executionId'] ?? null;

            if (is_string($executionId) && $executionId !== '') {
                $latestActivity[$executionId] = $item;
            }
        }

        $agents = [];

        foreach ($executionIds as $executionId) {
            /** @var ExecutionAttempt|null $attempt */
            $attempt = $attempts->get($executionId);

            /** @var ProviderSession|null $session */
            $session = $sessions->get($executionId);

            /** @var CodexApprovalRequest|null $approval */
            $approval = $approvals->get($executionId);

            $latest = $latestActivity[$executionId] ?? null;

            $diagnosticCode = $attempt->error_code
                ?? $session->recovery_reason
                ?? $session?->terminal_code;

            $agents[$executionId] = [
                'provider' => $attempt->execution_provider
                    ?? $session?->provider,
                'model' => $attempt->model_identifier
                    ?? $session?->model_identifier,
                'providerState' => $session?->status->value,
                'providerPhase' => $session?->lifecycle_phase->value,
                'providerSequence' => $session->last_provider_sequence ?? 0,
                'lastProviderMessageAt' => $session
                    ?->last_provider_message_at
                    ?->toIso8601String(),
                'recoveryRequired' => $session?->recovery_required_at !== null,
                'approvalRequired' => $approval instanceof CodexApprovalRequest,
                'approvalSummary' => $approval?->safe_summary,
                'approvalUrl' => $approval instanceof CodexApprovalRequest
                    ? route(
                        'organizations.projects.approvals.codex.show',
                        [
                            'organization' => $organizationId,
                            'project' => $projectId,
                            'codexApprovalRequest' => $approval,
                        ],
                        false,
                    )
                    : null,
                'estimatedCost' => $attempt?->estimated_cost,
                'actualCost' => $attempt?->actual_cost,
                'costCurrency' => $attempt?->cost_currency,
                'confidence' => $attempt?->confidence,
                'actualState' => $attempt?->actual_state,
                'elapsedSeconds' => $this->elapsedSeconds($attempt),
                'diagnosticCode' => $diagnosticCode,
                'diagnosticMessage' => $this->diagnosticMessage(
                    diagnosticCode: $diagnosticCode,
                    recoveryRequired: $session?->recovery_required_at !== null,
                ),
                'latestActivityState' => is_array($latest)
                    && is_string($latest['state'] ?? null)
                    ? $latest['state']
                    : null,
                'currentAction' => is_array($latest)
                    && is_string($latest['summary'] ?? null)
                    ? $latest['summary']
                    : null,
            ];
        }

        return [
            'agents' => $agents,
            'activity' => $activity,
        ];
    }

    /**
     * Return bounded provider activity ordered by the durable outbox sequence.
     *
     * Event payloads are deliberately ignored. Only allowlisted event identity
     * and canonical envelope metadata are projected.
     *
     * @param  list<string>  $executionIds
     * @return list<array<string, mixed>>
     */
    private function activity(
        int $organizationId,
        int $projectId,
        array $executionIds,
    ): array {
        return array_values(
            OutboxMessage::query()
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->whereIn('execution_id', $executionIds)
                ->whereIn('event_name', self::PROVIDER_EVENT_NAMES)
                ->latest('sequence')
                ->limit(self::ACTIVITY_LIMIT)
                ->get()
                ->reverse()
                ->values()
                ->map(function (OutboxMessage $message): array {
                    $descriptor = $this->activityDescriptor(
                        $message->event_name,
                    );

                    $provider = $message->envelope['provider'] ?? null;

                    return [
                        'sequence' => $message->sequence,
                        'eventId' => $message->event_id,
                        'executionId' => $message->execution_id,
                        'provider' => is_string($provider) && $provider !== ''
                            ? $provider
                            : null,
                        'state' => $descriptor['state'],
                        'summary' => $descriptor['summary'],
                        'occurredAt' => $message
                            ->occurred_at
                            ->toIso8601String(),
                    ];
                })
                ->all(),
        );
    }

    /**
     * Map one allowlisted provider event to a safe UI state and summary.
     *
     * @return array{state: string, summary: string}
     */
    private function activityDescriptor(string $eventName): array
    {
        return match ($eventName) {
            AuditEventType::ProviderSessionStarted->value => [
                'state' => 'reading_documents',
                'summary' => 'Starting provider-backed planning',
            ],
            AuditEventType::ProviderThreadStarted->value => [
                'state' => 'reading_documents',
                'summary' => 'Reading approved planning context',
            ],
            AuditEventType::ProviderTurnStarted->value => [
                'state' => 'planning',
                'summary' => 'Generating the project plan',
            ],
            AuditEventType::ProviderItemStarted->value => [
                'state' => 'reading_documents',
                'summary' => 'Processing approved planning context',
            ],
            AuditEventType::ProviderItemCompleted->value => [
                'state' => 'planning',
                'summary' => 'Continuing planning analysis',
            ],
            AuditEventType::ProviderApprovalRequested->value => [
                'state' => 'waiting_for_approval',
                'summary' => 'Waiting for an authorized provider decision',
            ],
            AuditEventType::ProviderApprovalResolved->value => [
                'state' => 'planning',
                'summary' => 'Continuing after provider approval',
            ],
            AuditEventType::ProviderCommandRequested->value => [
                'state' => 'planning',
                'summary' => 'Requesting an allowed planning operation',
            ],
            AuditEventType::ProviderCommandCompleted->value => [
                'state' => 'planning',
                'summary' => 'Allowed planning operation completed',
            ],
            AuditEventType::ProviderTurnCompleted->value => [
                'state' => 'validating',
                'summary' => 'Validating the provider planning result',
            ],
            AuditEventType::ProviderTurnFailed->value => [
                'state' => 'failed',
                'summary' => 'Provider planning turn failed',
            ],
            AuditEventType::ProviderSessionCancelled->value => [
                'state' => 'cancelled',
                'summary' => 'Provider planning session cancelled',
            ],
            default => [
                'state' => 'planning',
                'summary' => 'Provider planning activity updated',
            ],
        };
    }

    /**
     * Compute elapsed time from durable attempt timestamps.
     *
     * Running elapsed time is captured when the projection is rebuilt. The
     * browser never increments this value using a client-side workflow timer.
     */
    private function elapsedSeconds(?ExecutionAttempt $attempt): ?int
    {
        if ($attempt?->started_at === null) {
            return null;
        }

        $finishedAt = $attempt->finished_at ?? now();

        return max(
            0,
            (int) $attempt->started_at->diffInSeconds($finishedAt),
        );
    }

    /**
     * Convert machine-readable provider diagnostics into bounded safe text.
     */
    private function diagnosticMessage(
        ?string $diagnosticCode,
        bool $recoveryRequired,
    ): ?string {
        if ($recoveryRequired) {
            return 'Provider recovery is required before execution may continue.';
        }

        if ($diagnosticCode === null || trim($diagnosticCode) === '') {
            return null;
        }

        return 'Provider execution reported a diagnostic condition.';
    }
}
