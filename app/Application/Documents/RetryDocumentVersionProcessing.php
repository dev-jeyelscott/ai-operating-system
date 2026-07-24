<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Audit\Data\AuditContext;
use App\Domain\Audit\AuditEventType;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\AnalyzeDocumentVersionJob;
use App\Jobs\ParseDocumentVersionJob;
use App\Jobs\ScanDocumentVersionJob;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Safely requeues a transient failed stage without creating a new version.
 */
final class RetryDocumentVersionProcessing
{
    public function __construct(
        private RecordDocumentLifecycleEvent $events,
    ) {}

    /**
     * Reset and dispatch the appropriate failed processing stage.
     */
    public function handle(
        DocumentVersion $version,
        AuditContext $auditContext,
    ): void {
        $job = DB::transaction(
            function () use ($version, $auditContext): string {
                $lockedVersion = DocumentVersion::query()
                    ->lockForUpdate()
                    ->findOrFail($version->id);

                $previousStatus = $lockedVersion->status;
                $previousFailureCode = $lockedVersion->failure_code;

                $stage = match ($lockedVersion->status) {
                    DocumentStatus::ScanFailed => $this->retryScan(
                        $lockedVersion,
                    ),
                    DocumentStatus::ParseFailed => $this->retryParsing(
                        $lockedVersion,
                    ),
                    DocumentStatus::AnalysisFailed => $this->retryAnalysis(
                        $lockedVersion,
                    ),
                    default => throw new LogicException(
                        'Only failed document processing can be retried.',
                    ),
                };

                $retryEvent = $this->events->version(
                    version: $lockedVersion,
                    eventType: AuditEventType::DocumentProcessingRetryRequested,
                    context: $auditContext,
                    metadata: [
                        'stage' => $stage,
                        'previous_status' => $previousStatus->value,
                        'new_status' => $lockedVersion->status->value,
                        'previous_failure_code' => $previousFailureCode,
                    ],
                );

                return $stage.'|'.$retryEvent->eventId;
            },
        );

        [$stage, $causationId] = explode('|', $job, 2);
        $context = $auditContext->causedBy($causationId);

        match ($stage) {
            'scan' => ScanDocumentVersionJob::dispatch(
                documentVersionId: $version->id,
                correlationId: $context->correlationId,
                causationId: $context->causationId,
                executionId: $context->executionId,
            )->afterCommit(),
            'parse' => ParseDocumentVersionJob::dispatch(
                documentVersionId: $version->id,
                correlationId: $context->correlationId,
                causationId: $context->causationId,
                executionId: $context->executionId,
            )->afterCommit(),
            'analysis' => AnalyzeDocumentVersionJob::dispatch(
                documentVersionId: $version->id,
                correlationId: $context->correlationId,
                causationId: $context->causationId,
                executionId: $context->executionId,
            )->afterCommit(),
            default => throw new LogicException(
                'Unsupported document retry stage.',
            ),
        };
    }

    /**
     * Reset a transient malware-scanning failure.
     */
    private function retryScan(
        DocumentVersion $version,
    ): string {
        $version->forceFill([
            'status' => DocumentStatus::Quarantined,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        return 'scan';
    }

    /**
     * Reset a transient parser failure.
     */
    private function retryParsing(
        DocumentVersion $version,
    ): string {
        if (
            $version->failure_code
            === 'unsupported_media_type'
        ) {
            throw new LogicException(
                'Permanent parser capability failures cannot be retried.',
            );
        }

        $version->forceFill([
            'status' => DocumentStatus::ScanApproved,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        return 'parse';
    }

    /**
     * Reset terminal analysis failure while retaining previous safety flags.
     */
    private function retryAnalysis(
        DocumentVersion $version,
    ): string {
        $version->forceFill([
            'status' => DocumentStatus::AnalysisPending,
            'analysis_started_at' => null,
            'analysis_completed_at' => null,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        return 'analysis';
    }
}
