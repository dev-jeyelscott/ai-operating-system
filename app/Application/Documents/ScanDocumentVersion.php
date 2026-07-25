<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\Contracts\MalwareScanner;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditEventType;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Documents\MalwareScanResult;
use App\Jobs\ParseDocumentVersionJob;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Moves one quarantined document through the malware scan gate.
 */
final readonly class ScanDocumentVersion
{
    /**
     * Create the malware-scanning application service.
     */
    public function __construct(
        private MalwareScanner $malwareScanner,
        private RecordDocumentLifecycleEvent $events,
        private TransactionManager $transactions,
    ) {}

    /**
     * Move one quarantined version through the malware-scan gate.
     *
     * A queue retry may re-enter ScanPending. Only the first actual transition
     * emits the started event.
     */
    public function handle(
        int $documentVersionId,
        ?AuditContext $auditContext = null,
    ): void {
        $auditContext ??= AuditContext::system(actorId: 'document-scan-worker');
        $documentVersion = $this->transactions->run(function () use (
            $documentVersionId,
            $auditContext,
        ): ?DocumentVersion {
            $documentVersion = DocumentVersion::query()
                ->lockForUpdate()
                ->find($documentVersionId);

            if (! $documentVersion instanceof DocumentVersion) {
                throw (new ModelNotFoundException)->setModel(
                    DocumentVersion::class,
                    [$documentVersionId],
                );
            }

            if ($documentVersion->status === DocumentStatus::ScanPending) {
                return $documentVersion;
            }

            if ($documentVersion->status !== DocumentStatus::Quarantined) {
                return null;
            }

            $previousStatus = $documentVersion->status;

            $documentVersion->forceFill([
                'status' => DocumentStatus::ScanPending,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            $this->events->version(
                version: $documentVersion,
                eventType: AuditEventType::DocumentScanStarted,
                context: $auditContext,
                metadata: [
                    'previous_status' => $previousStatus->value,
                    'new_status' => DocumentStatus::ScanPending->value,
                ],
            );

            return $documentVersion->fresh();
        });

        if (! $documentVersion instanceof DocumentVersion) {
            return;
        }

        /*
     * Let Laravel retry thrown scanner failures. Terminal failure is recorded
     * only by the queue job's failed callback.
     */
        $result = $this->malwareScanner->scan($documentVersion);

        $this->transactions->run(function () use (
            $documentVersionId,
            $result,
            $auditContext,
        ): void {
            $documentVersion = DocumentVersion::query()
                ->lockForUpdate()
                ->findOrFail($documentVersionId);

            if ($documentVersion->status !== DocumentStatus::ScanPending) {
                return;
            }

            $previousStatus = $documentVersion->status;

            if ($result === MalwareScanResult::Clean) {
                $documentVersion->forceFill([
                    'status' => DocumentStatus::ScanApproved,
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();
            } else {
                $documentVersion->forceFill([
                    'status' => DocumentStatus::Quarantined,
                    'failure_code' => 'malware_detected',
                    'failure_message' => 'The file remains quarantined after the malware scan.',
                ])->save();
            }

            $completedEvent = $this->events->version(
                version: $documentVersion,
                eventType: AuditEventType::DocumentScanCompleted,
                context: $auditContext,
                metadata: [
                    'previous_status' => $previousStatus->value,
                    'new_status' => $documentVersion->status->value,
                    'result' => $result->value,
                ],
            );

            if ($result !== MalwareScanResult::Clean) {
                return;
            }

            ParseDocumentVersionJob::dispatch(
                documentVersionId: $documentVersion->id,
                correlationId: $auditContext->correlationId,
                causationId: $completedEvent->eventId,
                executionId: $auditContext->executionId,
            )->afterCommit();
        });
    }

    /**
     * Record terminal scan failure and its authoritative event atomically.
     */
    public function markFailed(
        int $documentVersionId,
        ?AuditContext $auditContext = null,
    ): void {
        $auditContext ??= AuditContext::system(actorId: 'document-scan-worker');
        $this->transactions->run(function () use (
            $documentVersionId,
            $auditContext,
        ): void {
            $documentVersion = DocumentVersion::query()
                ->lockForUpdate()
                ->findOrFail($documentVersionId);

            if ($documentVersion->status !== DocumentStatus::ScanPending) {
                return;
            }

            $previousStatus = $documentVersion->status;

            $documentVersion->forceFill([
                'status' => DocumentStatus::ScanFailed,
                'failure_code' => 'scan_unavailable',
                'failure_message' => 'The malware scan could not be completed.',
            ])->save();

            $this->events->version(
                version: $documentVersion,
                eventType: AuditEventType::DocumentScanFailed,
                context: $auditContext,
                metadata: [
                    'previous_status' => $previousStatus->value,
                    'new_status' => DocumentStatus::ScanFailed->value,
                    'failure_code' => 'scan_unavailable',
                ],
            );
        });
    }
}
