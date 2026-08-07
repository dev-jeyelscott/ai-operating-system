<?php

declare(strict_types=1);

namespace App\Application\Tickets;

use App\Application\Shared\Exceptions\ConflictException;
use App\Domain\Audit\AuditEventType;
use App\Domain\Tickets\TicketExternalStateSource;
use App\Domain\Tickets\TicketStatus;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reconciles external claims without mutating authoritative ticket truth.
 */
final readonly class ReconcileTicketExternalState
{
    public function __construct(
        private RecordTicketLifecycleEvents $events,
    ) {}

    public function handle(
        int $organizationId,
        int $projectId,
        int $roadmapId,
        int $ticketId,
        TicketExternalStateSource $source,
        string $idempotencyKey,
        string $actorId,
        string $correlationId,
        ?TicketStatus $desiredState = null,
        ?string $reportedState = null,
        ?string $observedState = null,
    ): RoadmapTask {
        $this->assertIdentifiers(
            organizationId: $organizationId,
            projectId: $projectId,
            roadmapId: $roadmapId,
            ticketId: $ticketId,
            idempotencyKey: $idempotencyKey,
            actorId: $actorId,
            correlationId: $correlationId,
        );

        $reportedState = $this->normalizeExternalState($reportedState);
        $observedState = $this->normalizeExternalState($observedState);
        $this->assertSourcePolicy(
            source: $source,
            desiredState: $desiredState,
            reportedState: $reportedState,
            observedState: $observedState,
        );

        $idempotencyKeyHash = hash('sha256', $idempotencyKey);
        $requestFingerprint = TicketCommandFingerprint::make([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'roadmap_id' => $roadmapId,
            'ticket_id' => $ticketId,
            'source' => $source->value,
            'actor_id' => $actorId,
            'desired_state' => $desiredState?->value,
            'reported_state' => $reportedState,
            'observed_state' => $observedState,
        ]);

        return DB::transaction(function () use (
            $organizationId,
            $projectId,
            $roadmapId,
            $ticketId,
            $source,
            $idempotencyKeyHash,
            $requestFingerprint,
            $actorId,
            $correlationId,
            $desiredState,
            $reportedState,
            $observedState,
        ): RoadmapTask {
            $project = Project::query()
                ->forOrganization($organizationId)
                ->whereKey($projectId)
                ->lock('for share')
                ->firstOrFail();

            Roadmap::query()
                ->where('project_id', $project->id)
                ->whereKey($roadmapId)
                ->lock('for share')
                ->firstOrFail();

            $ticket = RoadmapTask::query()
                ->where('roadmap_id', $roadmapId)
                ->whereKey($ticketId)
                ->lockForUpdate()
                ->firstOrFail();

            $deduplicationKey = RecordTicketLifecycleEvents::deduplicationKey(
                eventType: AuditEventType::TicketExternalStateReconciled,
                ticketId: $ticket->id,
                idempotencyKeyHash: $idempotencyKeyHash,
            );

            if ($this->isExactReplay(
                project: $project,
                deduplicationKey: $deduplicationKey,
                requestFingerprint: $requestFingerprint,
            )) {
                return $ticket;
            }

            $updates = [];

            if ($desiredState !== null) {
                $updates['desired_state'] = $desiredState;
            }

            if ($reportedState !== null) {
                $updates['reported_state'] = $reportedState;
            }

            if ($observedState !== null) {
                $updates['observed_state'] = $observedState;
            }

            $ticket->forceFill($updates)->save();
            $ticket->refresh();
            $observedAt = CarbonImmutable::now();

            $this->events->reconciled(
                project: $project,
                ticket: $ticket,
                source: $source,
                actorId: $actorId,
                correlationId: $correlationId,
                idempotencyKeyHash: $idempotencyKeyHash,
                requestFingerprint: $requestFingerprint,
                observedAt: $observedAt,
            );

            return $ticket;
        }, attempts: 3);
    }

    private function normalizeExternalState(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        if (mb_strlen($state) > 120) {
            throw new InvalidArgumentException(
                'External ticket state may not exceed 120 characters.',
            );
        }

        $normalized = (string) Str::of($state)
            ->ascii()
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_');

        if (preg_match('/\A[a-z][a-z0-9_]{1,119}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException(
                'External ticket state is invalid or unsupported.',
            );
        }

        return $normalized;
    }

    private function assertSourcePolicy(
        TicketExternalStateSource $source,
        ?TicketStatus $desiredState,
        ?string $reportedState,
        ?string $observedState,
    ): void {
        if ($desiredState === null
            && $reportedState === null
            && $observedState === null) {
            throw new InvalidArgumentException(
                'At least one external ticket state is required.',
            );
        }

        $permitted = match ($source) {
            TicketExternalStateSource::Notion => $observedState === null,
            TicketExternalStateSource::ExecutionProvider => $desiredState === null
                && $observedState === null,
            TicketExternalStateSource::DeterministicObserver => $desiredState === null
                && $reportedState === null,
        };

        if (! $permitted) {
            throw new InvalidArgumentException(
                'The external ticket state fields are not permitted for this source.',
            );
        }
    }

    private function isExactReplay(
        Project $project,
        string $deduplicationKey,
        string $requestFingerprint,
    ): bool {
        $existing = AuditEvent::query()
            ->where('organization_id', $project->organization_id)
            ->where('deduplication_key', $deduplicationKey)
            ->first();

        if ($existing === null) {
            return false;
        }

        $existingFingerprint = $existing->metadata['request_fingerprint'] ?? null;

        if (! is_string($existingFingerprint)
            || ! hash_equals($existingFingerprint, $requestFingerprint)) {
            throw new ConflictException(
                'The ticket reconciliation idempotency key was reused with different input.',
            );
        }

        return true;
    }

    private function assertIdentifiers(
        int $organizationId,
        int $projectId,
        int $roadmapId,
        int $ticketId,
        string $idempotencyKey,
        string $actorId,
        string $correlationId,
    ): void {
        if (min($organizationId, $projectId, $roadmapId, $ticketId) < 1) {
            throw new InvalidArgumentException(
                'Ticket reconciliation identifiers must be positive.',
            );
        }

        foreach ([
            'idempotency key' => $idempotencyKey,
            'actor identifier' => $actorId,
            'correlation identifier' => $correlationId,
        ] as $name => $value) {
            if ($value === ''
                || trim($value) !== $value
                || mb_strlen($value) > 128
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/', $value) !== 1) {
                throw new InvalidArgumentException(
                    sprintf('The ticket reconciliation %s is invalid.', $name),
                );
            }
        }
    }
}
