<?php

namespace App\Application\Planning\Notion;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Shared\Exceptions\ConflictException;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Projects\ProjectStatus;
use App\Models\ExternalTicketMapping;
use App\Models\NotionReconciliationConflict;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Authorizes a canonical internal overwrite without bypassing reconciliation. */
final readonly class RetainInternalNotionConflict
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(NotionReconciliationConflict $conflict, User $actor, string $expectedFingerprint, string $reason, ?string $correlationId = null): NotionReconciliationConflict
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required when retaining internal Notion content.');
        }

        return DB::transaction(function () use ($conflict, $actor, $expectedFingerprint, $reason, $correlationId): NotionReconciliationConflict {
            $lockedConflict = NotionReconciliationConflict::query()->with('mapping.task.roadmap')->lockForUpdate()->findOrFail($conflict->id);
            $mapping = ExternalTicketMapping::query()->lockForUpdate()->findOrFail($lockedConflict->external_ticket_mapping_id);
            $roadmap = $lockedConflict->mapping->task->roadmap;
            $project = Project::query()->lockForUpdate()->findOrFail($roadmap->project_id);
            Gate::forUser($actor)->authorize('approve', $project);

            if ($lockedConflict->organization_id !== $project->organization_id || $lockedConflict->project_id !== $project->id || ! hash_equals($lockedConflict->current_fingerprint, $expectedFingerprint) || ! hash_equals((string) $mapping->reconciliation_fingerprint, $expectedFingerprint)) {
                throw new ConflictException('The Notion reconciliation conflict is stale.');
            }
            if ($lockedConflict->state === 'resolved' && $lockedConflict->decision === 'retain_internal') {
                return $lockedConflict;
            }
            if (! in_array($lockedConflict->state, ['open', 'republish_failed'], true) || $roadmap->status !== 'approved' || $project->status !== ProjectStatus::ReadyForDevelopment) {
                throw new ConflictException('The Notion conflict is no longer eligible for internal republishing.');
            }

            $lockedConflict->update(['state' => 'republish_queued', 'decision' => 'retain_internal', 'decision_reason' => $reason, 'decided_by_user_id' => $actor->id, 'decided_at' => now()]);
            $mapping->update(['state' => 'pending', 'failure_metadata' => null, 'reconciliation_state' => 'republish_authorized']);
            $this->audit->record($project->organization_id, $project->id, AuditActorType::User, (string) $actor->id, AuditEventType::NotionConflictRetainInternalRequested, AuditSubjectType::ExternalTicketMapping, (string) $mapping->id, $correlationId, ['conflict_id' => $lockedConflict->id, 'before_fingerprint' => $lockedConflict->published_fingerprint, 'external_fingerprint' => $lockedConflict->external_fingerprint, 'publication_action' => 'republish']);

            return $lockedConflict->fresh();
        }, attempts: 3);
    }
}
