<?php

declare(strict_types=1);

namespace App\Application\Codex\Approvals;

use App\Application\Approvals\Commands\DecideApproval;
use App\Application\Approvals\Handlers\DecideApprovalHandler;
use App\Application\Shared\Commands\CommandResult;
use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Codex\CodexApprovalAction;
use App\Models\CodexApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Bridges application-owned human decisions into the generic approval engine.
 *
 * The provider never decides its own privileges. This service validates the
 * immutable Codex request lineage, reauthorizes the actor, and delegates the
 * authoritative decision to the existing deterministic approval handler.
 */
final readonly class CodexApprovalBridge
{
    /**
     * Inject the existing deterministic approval decision handler.
     */
    public function __construct(
        private DecideApprovalHandler $decideApproval,
    ) {}

    /**
     * Apply or defer one tenant-scoped Codex approval decision.
     *
     * Defer intentionally leaves the underlying generic approval pending.
     * Approve, deny, and cancel are delegated to the generic approval engine
     * so its authorization, expiry, locking, idempotency, and audit behavior
     * remain the authoritative source of truth.
     */
    public function decide(
        int $organizationId,
        int $projectId,
        string $codexApprovalRequestId,
        int $actorUserId,
        CodexApprovalAction $action,
        ?string $reason = null,
    ): CommandResult {
        $this->validateIdentifiers(
            organizationId: $organizationId,
            projectId: $projectId,
            codexApprovalRequestId: $codexApprovalRequestId,
            actorUserId: $actorUserId,
        );

        $codexRequest = $this->resolveRequest(
            organizationId: $organizationId,
            projectId: $projectId,
            codexApprovalRequestId: $codexApprovalRequestId,
        );

        $actor = User::query()->findOrFail($actorUserId);

        /*
         * Reauthorize against the exact project at the bridge boundary.
         *
         * Terminal decisions are authorized again inside
         * DecideApprovalHandler immediately before the approval row changes.
         */
        Gate::forUser($actor)->authorize(
            'approve',
            $codexRequest->project,
        );

        if ($action === CodexApprovalAction::Defer) {
            return CommandResult::succeeded([
                'codex_approval_request_id' => $codexRequest->id,
                'approval_id' => $codexRequest->approval_id,
                'project_id' => $codexRequest->project_id,
                'status' => $codexRequest->approval->status->value,
                'deferred' => true,
            ]);
        }

        return $this->decideApproval->handle(
            new DecideApproval(
                approvalId: $codexRequest->approval_id,
                actorUserId: $actor->id,
                decision: $this->approvalDecisionFor($action),
                idempotencyKey: sprintf(
                    'codex-decision:%s:%d:%s',
                    $codexRequest->id,
                    $actor->id,
                    $action->value,
                ),
                correlationId: sprintf(
                    'codex-approval:%s',
                    $codexRequest->id,
                ),
                reason: $reason,
                causationId: $codexRequest->provider_event_id,
            ),
        );
    }

    /**
     * Resolve the exact immutable Codex request within its tenant boundary.
     */
    private function resolveRequest(
        int $organizationId,
        int $projectId,
        string $codexApprovalRequestId,
    ): CodexApprovalRequest {
        /** @var CodexApprovalRequest $codexRequest */
        $codexRequest = CodexApprovalRequest::query()
            ->with([
                'approval',
                'project',
            ])
            ->where('organization_id', $organizationId)
            ->forProject($projectId)
            ->whereKey($codexApprovalRequestId)
            ->firstOrFail();

        /*
         * Fail closed if persisted bridge lineage has somehow become
         * inconsistent. A provider decision must never cross project bounds.
         */
        if (
            $codexRequest->project_id !== $projectId
            || $codexRequest->approval->project_id !== $projectId
        ) {
            throw new LogicException(
                'The Codex approval request has inconsistent project lineage.',
            );
        }

        return $codexRequest;
    }

    /**
     * Map the provider-neutral human action to the generic approval decision.
     *
     * Cancel rejects authorization at the application layer. The distinction
     * between deny and cancel remains available through the Codex action when
     * the provider-delivery layer prepares its exact JSON-RPC response.
     */
    private function approvalDecisionFor(
        CodexApprovalAction $action,
    ): ApprovalDecision {
        return match ($action) {
            CodexApprovalAction::Approve => ApprovalDecision::Approve,

            CodexApprovalAction::Deny,
            CodexApprovalAction::Cancel => ApprovalDecision::Reject,

            CodexApprovalAction::Defer => throw new LogicException(
                'Deferred Codex approvals must not create a terminal decision.',
            ),
        };
    }

    /**
     * Validate externally supplied tenant, actor, and bridge identifiers.
     */
    private function validateIdentifiers(
        int $organizationId,
        int $projectId,
        string $codexApprovalRequestId,
        int $actorUserId,
    ): void {
        if ($organizationId < 1) {
            throw new InvalidArgumentException(
                'The organization identifier must be positive.',
            );
        }

        if ($projectId < 1) {
            throw new InvalidArgumentException(
                'The project identifier must be positive.',
            );
        }

        if ($actorUserId < 1) {
            throw new InvalidArgumentException(
                'The approval decision actor identifier must be positive.',
            );
        }

        if (! Str::isUlid($codexApprovalRequestId)) {
            throw new InvalidArgumentException(
                'The Codex approval request identifier must be a valid ULID.',
            );
        }
    }
}
