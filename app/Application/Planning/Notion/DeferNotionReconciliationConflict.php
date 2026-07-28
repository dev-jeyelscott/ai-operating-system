<?php

namespace App\Application\Planning\Notion;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Shared\Exceptions\ConflictException;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Models\ExternalTicketMapping;
use App\Models\NotionReconciliationConflict;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Defers a conflict and prevents any write until a later explicit decision. */
final readonly class DeferNotionReconciliationConflict
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(NotionReconciliationConflict $conflict, User $actor, string $expectedFingerprint, string $reason, ?string $correlationId = null): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required when deferring a Notion conflict.');
        }

        DB::transaction(function () use ($conflict, $actor, $expectedFingerprint, $reason, $correlationId): void {
            $lockedConflict = NotionReconciliationConflict::query()->with('mapping.task.roadmap')->lockForUpdate()->findOrFail($conflict->id);
            $mapping = ExternalTicketMapping::query()->lockForUpdate()->findOrFail($lockedConflict->external_ticket_mapping_id);
            $project = Project::query()->lockForUpdate()->findOrFail($lockedConflict->mapping->task->roadmap->project_id);
            Gate::forUser($actor)->authorize('approve', $project);

            if ($lockedConflict->organization_id !== $project->organization_id || $lockedConflict->project_id !== $project->id || ! hash_equals($lockedConflict->current_fingerprint, $expectedFingerprint) || ! hash_equals((string) $mapping->reconciliation_fingerprint, $expectedFingerprint)) {
                throw new ConflictException('The Notion reconciliation conflict is stale.');
            }
            if ($lockedConflict->state === 'deferred' && $lockedConflict->decision === 'defer') {
                return;
            }
            if ($lockedConflict->state !== 'open') {
                throw new ConflictException('The Notion conflict is no longer open for deferral.');
            }

            $lockedConflict->update(['state' => 'deferred', 'decision' => 'defer', 'decision_reason' => $reason, 'decided_by_user_id' => $actor->id, 'decided_at' => now()]);
            $mapping->update(['reconciliation_state' => 'deferred']);
            $this->audit->record($project->organization_id, $project->id, AuditActorType::User, (string) $actor->id, AuditEventType::NotionConflictDeferred, AuditSubjectType::ExternalTicketMapping, (string) $mapping->id, $correlationId, ['conflict_id' => $lockedConflict->id, 'before_fingerprint' => $lockedConflict->published_fingerprint, 'external_fingerprint' => $lockedConflict->external_fingerprint]);
        }, attempts: 3);
    }
}
