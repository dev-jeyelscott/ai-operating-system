<?php

declare(strict_types=1);

namespace App\Application\Documents;

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
    /**
     * Reset and dispatch the appropriate failed processing stage.
     */
    public function handle(DocumentVersion $version): void
    {
        $job = DB::transaction(
            function () use ($version): string {
                $lockedVersion = DocumentVersion::query()
                    ->lockForUpdate()
                    ->findOrFail($version->id);

                return match ($lockedVersion->status) {
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
            },
        );

        match ($job) {
            'scan' => ScanDocumentVersionJob::dispatch(
                $version->id,
            )->afterCommit(),
            'parse' => ParseDocumentVersionJob::dispatch(
                $version->id,
            )->afterCommit(),
            'analysis' => AnalyzeDocumentVersionJob::dispatch(
                $version->id,
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
