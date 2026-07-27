<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\Contracts\DocumentAnalyzer;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditEventType;
use App\Domain\Documents\DocumentStatus;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Log;

/**
 * Coordinates deterministic document analysis and authoritative lifecycle events.
 */
final readonly class AnalyzeDocumentVersion
{
    /**
     * Create the document-analysis application service.
     */
    public function __construct(
        private DocumentAnalyzer $analyzer,
        private RecordDocumentLifecycleEvent $events,
        private TransactionManager $transactions,
    ) {}

    /**
     * Analyze one pending document version idempotently.
     *
     * State acquisition and completion are protected by database row locks.
     * Provider execution runs outside the transaction so a slow analyzer does
     * not hold database locks for the duration of the analysis.
     */
    public function handle(
        int $id,
        int $seed,
        ?AuditContext $auditContext = null,
    ): void {
        $auditContext ??= AuditContext::system(
            actorId: 'document-analysis-worker',
        );

        /*
         * Resolve provider identity once so persisted provenance, lifecycle
         * events, and structured logs always use the same values.
         */
        $analyzerName = $this->analyzer->name();
        $analyzerVersion = $this->analyzer->version();

        $version = $this->transactions->run(
            function () use (
                $id,
                $seed,
                $auditContext,
                $analyzerName,
                $analyzerVersion,
            ): ?DocumentVersion {
                $version = DocumentVersion::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                if (
                    $version->status
                    === DocumentStatus::AnalysisPending
                ) {
                    $version->beginAnalysis(
                        analyzerName: $analyzerName,
                        analyzerVersion: $analyzerVersion,
                        seed: $seed,
                    );

                    $this->events->version(
                        version: $version,
                        eventType: AuditEventType::DocumentAnalysisStarted,
                        context: $auditContext,
                        metadata: [
                            'previous_status' => DocumentStatus::AnalysisPending->value,
                            'new_status' => DocumentStatus::Analyzing->value,
                            'analyzer_name' => $analyzerName,
                            'analyzer_version' => $analyzerVersion,
                            'analysis_seed' => $seed,
                        ],
                    );

                    return $version->fresh();
                }

                /*
                 * A queue retry may re-enter after a previous attempt moved the
                 * version into Analyzing and then encountered an exception.
                 *
                 * Only the same deterministic analysis attempt may resume.
                 */
                if (
                    $version->status === DocumentStatus::Analyzing
                    && $version->analysis_seed === $seed
                    && $version->analyzer_name === $analyzerName
                    && $version->analyzer_version === $analyzerVersion
                ) {
                    return $version;
                }

                return null;
            },
        );

        if (! $version instanceof DocumentVersion) {
            return;
        }

        Log::info('document.analysis_started', [
            'document_version_id' => $id,
            'analyzer_name' => $analyzerName,
            'analyzer_version' => $analyzerVersion,
            'analysis_seed' => $seed,
            'correlation_id' => $auditContext->correlationId,
            'causation_id' => $auditContext->causationId,
            'execution_id' => $auditContext->executionId,
        ]);

        /*
         * Analyzer execution deliberately occurs outside the database
         * transaction to avoid holding a row lock during provider work.
         */
        $analysis = $this->analyzer->analyze(
            version: $version,
            seed: $seed,
        );

        $completed = $this->transactions->run(
            function () use (
                $id,
                $seed,
                $analysis,
                $auditContext,
                $analyzerName,
                $analyzerVersion,
            ): bool {
                $version = DocumentVersion::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                /*
                 * Ignore stale or duplicate completion attempts.
                 *
                 * The seed and analyzer provenance must still match the
                 * analysis attempt that acquired the lifecycle state.
                 */
                if (
                    $version->status !== DocumentStatus::Analyzing
                    || $version->analysis_seed !== $seed
                    || $version->analyzer_name !== $analyzerName
                    || $version->analyzer_version !== $analyzerVersion
                ) {
                    return false;
                }

                /*
                 * Never silently remove a previously surfaced safety warning.
                 * A deterministic retry may add flags but cannot erase them.
                 */
                $flags = array_values(array_unique([
                    ...($version->analysis_flags ?? []),
                    ...$analysis->flags,
                ]));

                $version->forceFill([
                    'status' => DocumentStatus::NeedsReview,
                    'classification' => $analysis->classification,
                    'analysis_summary' => $analysis->summary,
                    'analysis_conflicts' => $analysis->conflicts,
                    'analysis_gaps' => $analysis->gaps,
                    'analysis_flags' => $flags,
                    'analysis_completed_at' => now(),
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();

                $this->events->version(
                    version: $version,
                    eventType: AuditEventType::DocumentAnalysisCompleted,
                    context: $auditContext,
                    metadata: [
                        'previous_status' => DocumentStatus::Analyzing->value,
                        'new_status' => DocumentStatus::NeedsReview->value,
                        'analyzer_name' => $analyzerName,
                        'analyzer_version' => $analyzerVersion,
                        'analysis_seed' => $seed,
                        'safety_flag_count' => count($flags),
                        'conflict_count' => count($analysis->conflicts),
                        'gap_count' => count($analysis->gaps),
                    ],
                );

                return true;
            },
        );

        if (! $completed) {
            return;
        }

        Log::info('document.analysis_completed', [
            'document_version_id' => $id,
            'analyzer_name' => $analyzerName,
            'analyzer_version' => $analyzerVersion,
            'analysis_seed' => $seed,
            'correlation_id' => $auditContext->correlationId,
            'causation_id' => $auditContext->causationId,
            'execution_id' => $auditContext->executionId,
        ]);
    }

    /**
     * Record terminal analysis failure after queue attempts are exhausted.
     */
    public function markFailed(
        int $id,
        ?AuditContext $auditContext = null,
    ): void {
        $auditContext ??= AuditContext::system(
            actorId: 'document-analysis-worker',
        );

        $failed = $this->transactions->run(
            function () use ($id, $auditContext): bool {
                $version = DocumentVersion::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                /*
                 * A stale failed callback must never overwrite completed,
                 * reviewed, rejected, approved, or superseded evidence.
                 */
                if ($version->status !== DocumentStatus::Analyzing) {
                    return false;
                }

                $version->forceFill([
                    'status' => DocumentStatus::AnalysisFailed,
                    'analysis_completed_at' => null,
                    'failure_code' => 'analysis_failed',
                    'failure_message' => 'The document analysis could not be completed.',
                ])->save();

                $this->events->version(
                    version: $version,
                    eventType: AuditEventType::DocumentAnalysisFailed,
                    context: $auditContext,
                    metadata: [
                        'previous_status' => DocumentStatus::Analyzing->value,
                        'new_status' => DocumentStatus::AnalysisFailed->value,
                        'analyzer_name' => $version->analyzer_name,
                        'analyzer_version' => $version->analyzer_version,
                        'analysis_seed' => $version->analysis_seed,
                        'failure_code' => 'analysis_failed',
                    ],
                );

                return true;
            },
        );

        if (! $failed) {
            return;
        }

        Log::warning('document.analysis_failed', [
            'document_version_id' => $id,
            'failure_code' => 'analysis_failed',
            'correlation_id' => $auditContext->correlationId,
            'causation_id' => $auditContext->causationId,
            'execution_id' => $auditContext->executionId,
        ]);
    }
}
