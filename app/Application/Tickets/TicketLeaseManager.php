<?php

declare(strict_types=1);

namespace App\Application\Tickets;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Approvals\ApprovalType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketLeaseReleaseReason;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\Project;
use App\Models\TicketExecutionLease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class TicketLeaseManager
{
    public function __construct(
        private RecordTicketLeaseLifecycleEvents $events,
    ) {}

    public function heartbeat(
        int $organizationId,
        int $projectId,
        string $leaseId,
        string $executionId,
        string $owner,
    ): TicketExecutionLease {
        return DB::transaction(function () use ($organizationId, $projectId, $leaseId, $executionId, $owner): TicketExecutionLease {
            [$project, $execution, $lease] = $this->lockLineage($organizationId, $projectId, $leaseId, $executionId);

            if ($lease->owner !== $owner || ! $lease->isActive()) {
                throw new \LogicException('Lease heartbeat ownership is invalid.');
            }

            if ($execution->status->isTerminal()) {
                throw new \LogicException('Terminal execution cannot heartbeat a lease.');
            }

            $heartbeatAt = CarbonImmutable::now();

            if ($heartbeatAt->greaterThanOrEqualTo($lease->expires_at)) {
                throw new \LogicException('Expired lease cannot be revived by heartbeat.');
            }

            if ($heartbeatAt->equalTo($lease->heartbeat_at)) {
                return $lease;
            }

            if ($heartbeatAt->lessThan($lease->heartbeat_at)) {
                throw new \LogicException('Lease heartbeat must move forward.');
            }

            $intervalMicroseconds = $lease->heartbeat_at->diffInMicroseconds($lease->expires_at);
            $lease->forceFill([
                'heartbeat_at' => $heartbeatAt,
                'expires_at' => $heartbeatAt->addMicroseconds($intervalMicroseconds),
            ])->save();
            $this->events->record($project, $execution, $lease, AuditEventType::TicketLeaseHeartbeat, $heartbeatAt);

            return $lease;
        });
    }

    public function releaseForExecution(
        int $organizationId,
        int $projectId,
        string $leaseId,
        string $executionId,
        string $owner,
        TicketLeaseReleaseReason $reason,
    ): TicketExecutionLease {
        return DB::transaction(function () use ($organizationId, $projectId, $leaseId, $executionId, $owner, $reason): TicketExecutionLease {
            [$project, $execution, $lease] = $this->lockLineage($organizationId, $projectId, $leaseId, $executionId);

            if ($lease->owner !== $owner) {
                throw new \LogicException('Lease release ownership is invalid.');
            }

            if (! $lease->isActive()) {
                if ($lease->release_reason === $reason) {
                    return $lease;
                }

                throw new \LogicException('Lease was released for a different reason.');
            }

            $this->assertReleasePermitted($execution, $lease, $reason);
            $releasedAt = CarbonImmutable::now();
            $lease->forceFill(['released_at' => $releasedAt, 'release_reason' => $reason])->save();
            $this->events->record($project, $execution, $lease, AuditEventType::TicketLeaseReleased, $releasedAt);

            return $lease;
        });
    }

    /**
     * Release a lease whose project, execution, and lease rows are already
     * locked by an enclosing orchestration transaction.
     */
    public function releaseLocked(
        Project $project,
        Execution $execution,
        TicketExecutionLease $lease,
        string $owner,
        TicketLeaseReleaseReason $reason,
    ): TicketExecutionLease {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('A locked lease release requires an active transaction.');
        }

        if ($execution->project_id !== $project->id
            || $lease->project_id !== $project->id
            || $lease->execution_id !== $execution->id
            || $lease->owner !== $owner) {
            throw new \LogicException('Locked lease release lineage is invalid.');
        }

        if (! $lease->isActive()) {
            if ($lease->release_reason === $reason) {
                return $lease;
            }

            throw new \LogicException('Lease was released for a different reason.');
        }

        $this->assertReleasePermitted($execution, $lease, $reason);
        $releasedAt = CarbonImmutable::now();
        $lease->forceFill(['released_at' => $releasedAt, 'release_reason' => $reason])->save();
        $this->events->record($project, $execution, $lease, AuditEventType::TicketLeaseReleased, $releasedAt);

        return $lease;
    }

    public function recoverExpired(int $limit = 200): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Recovery limit must be between 1 and 1000.');
        }

        $executionIds = Execution::query()
            ->whereIn('status', [ExecutionStatus::Completed, ExecutionStatus::Failed, ExecutionStatus::Cancelled, ExecutionStatus::Blocked])
            ->whereHas('ticketLeases', fn ($query) => $query->whereNull('released_at')->where('expires_at', '<=', CarbonImmutable::now()))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $released = 0;

        foreach ($executionIds as $executionId) {
            $released += DB::transaction(function () use ($executionId): int {
                $execution = Execution::query()->whereKey($executionId)->lock('for update skip locked')->first();

                if ($execution === null) {
                    return 0;
                }

                $lease = TicketExecutionLease::query()
                    ->where('execution_id', $execution->id)
                    ->active()
                    ->where('expires_at', '<=', CarbonImmutable::now())
                    ->lockForUpdate()
                    ->first();

                if ($lease === null) {
                    return 0;
                }

                $reason = match ($execution->status) {
                    ExecutionStatus::Completed => TicketLeaseReleaseReason::Completion,
                    ExecutionStatus::Failed => TicketLeaseReleaseReason::TerminalFailure,
                    ExecutionStatus::Cancelled => TicketLeaseReleaseReason::Cancellation,
                    ExecutionStatus::Blocked => TicketLeaseReleaseReason::ManualRecovery,
                    default => null,
                };

                if ($reason === null || ($reason === TicketLeaseReleaseReason::ManualRecovery && ! $this->manualRecoveryApproved($execution, $lease))) {
                    return 0;
                }

                $project = Project::query()->whereKey($execution->project_id)->firstOrFail();
                $releasedAt = CarbonImmutable::now();
                $lease->forceFill(['released_at' => $releasedAt, 'release_reason' => $reason])->save();
                $this->events->record($project, $execution, $lease, AuditEventType::TicketLeaseReleased, $releasedAt);

                return 1;
            });
        }

        return $released;
    }

    /** @return array{Project, Execution, TicketExecutionLease} */
    private function lockLineage(int $organizationId, int $projectId, string $leaseId, string $executionId): array
    {
        $project = Project::query()->forOrganization($organizationId)->whereKey($projectId)->firstOrFail();
        $execution = Execution::query()->forProject($project->id)->whereKey($executionId)->lockForUpdate()->firstOrFail();
        $lease = TicketExecutionLease::query()
            ->where('project_id', $project->id)
            ->where('execution_id', $execution->id)
            ->whereKey($leaseId)
            ->lockForUpdate()
            ->firstOrFail();

        return [$project, $execution, $lease];
    }

    private function assertReleasePermitted(Execution $execution, TicketExecutionLease $lease, TicketLeaseReleaseReason $reason): void
    {
        $expected = match ($execution->status) {
            ExecutionStatus::Completed => TicketLeaseReleaseReason::Completion,
            ExecutionStatus::Failed => TicketLeaseReleaseReason::TerminalFailure,
            ExecutionStatus::Cancelled => TicketLeaseReleaseReason::Cancellation,
            ExecutionStatus::Blocked => TicketLeaseReleaseReason::ManualRecovery,
            default => null,
        };

        if ($expected !== $reason || ($reason === TicketLeaseReleaseReason::ManualRecovery && ! $this->manualRecoveryApproved($execution, $lease))) {
            throw new \LogicException('Lease release is not permitted by execution liveness policy.');
        }
    }

    private function manualRecoveryApproved(Execution $execution, TicketExecutionLease $lease): bool
    {
        return Approval::query()
            ->forProject($execution->project_id)
            ->where('type', ApprovalType::Execution->value)
            ->where('status', ApprovalStatus::Approved->value)
            ->where('request_payload->action', 'manual_recovery')
            ->where('request_payload->execution_id', $execution->id)
            ->where('request_payload->lease_id', $lease->id)
            ->exists();
    }
}
