<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Planning\Notion\UpsertNotionTicket;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Models\ExternalTicketMapping;
use App\Models\NotionReconciliationConflict;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Executes the external overwrite only after a retained-internal decision commits. */
final class RepublishNotionConflictJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $conflictId,
        public readonly int $actorUserId,
        public readonly int $organizationId,
        public readonly ?string $correlationId,
    ) {}

    public function uniqueId(): string
    {
        return 'notion-conflict-republish:'.$this->conflictId;
    }

    public function handle(UpsertNotionTicket $upsert, RecordAuditEvent $audit): void
    {
        $conflict = NotionReconciliationConflict::query()->with('mapping.task.roadmap')->findOrFail($this->conflictId);
        if ($conflict->state !== 'republish_queued' || $conflict->decision !== 'retain_internal') {
            return;
        }

        $result = $upsert->handle($this->actorUserId, $this->organizationId, $conflict->mapping->task, $this->correlationId);
        if (! in_array($result->outcome, ['created', 'updated', 'skipped'], true)) {
            DB::transaction(function () use ($conflict): void {
                NotionReconciliationConflict::query()->lockForUpdate()->findOrFail($conflict->id)->update(['state' => 'republish_failed']);
            });

            return;
        }

        DB::transaction(function () use ($conflict, $audit, $result): void {
            $locked = NotionReconciliationConflict::query()->lockForUpdate()->findOrFail($conflict->id);
            if ($locked->state !== 'republish_queued' || $locked->decision !== 'retain_internal') {
                return;
            }
            $mapping = ExternalTicketMapping::query()->lockForUpdate()->findOrFail($locked->external_ticket_mapping_id);
            $locked->update(['state' => 'resolved']);
            $mapping->update(['reconciliation_state' => 'in_sync']);
            $audit->record($locked->organization_id, $locked->project_id, AuditActorType::User, (string) $this->actorUserId, AuditEventType::NotionConflictRetainInternalCompleted, AuditSubjectType::ExternalTicketMapping, (string) $mapping->id, $this->correlationId, ['conflict_id' => $locked->id, 'outcome' => $result->outcome, 'page_url' => $result->pageUrl]);
        }, attempts: 3);
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            NotionReconciliationConflict::query()
                ->lockForUpdate()
                ->whereKey($this->conflictId)
                ->where('state', 'republish_queued')
                ->update(['state' => 'republish_failed']);
        });
    }
}
