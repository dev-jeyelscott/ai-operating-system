<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Contracts\DocumentAnalyzer;
use App\Domain\Documents\DocumentStatus;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class AnalyzeDocumentVersion
{
    public function __construct(
        private DocumentAnalyzer $analyzer,
    ) {}

    /**
     * Analyze one pending document version idempotently.
     *
     * Analysis runs outside the database transaction. State acquisition and
     * completion are separately guarded with row locks.
     */
    public function handle(int $id, int $seed): void
    {
        $version = DB::transaction(
            function () use ($id, $seed): ?DocumentVersion {
                $version = DocumentVersion::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                if (
                    $version->status
                    === DocumentStatus::AnalysisPending
                ) {
                    $version->beginAnalysis(
                        analyzerName: $this->analyzer->name(),
                        analyzerVersion: $this->analyzer->version(),
                        seed: $seed,
                    );

                    return $version->fresh();
                }

                /*
                 * A queue retry may re-enter after the previous attempt moved
                 * the version into Analyzing and then threw an exception.
                 */
                if (
                    $version->status === DocumentStatus::Analyzing
                    && $version->analysis_seed === $seed
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
            'analyzer_name' => $this->analyzer->name(),
            'analyzer_version' => $this->analyzer->version(),
            'analysis_seed' => $seed,
        ]);

        $analysis = $this->analyzer->analyze(
            version: $version,
            seed: $seed,
        );

        $completed = DB::transaction(
            function () use ($id, $seed, $analysis): bool {
                $version = DocumentVersion::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                if (
                    $version->status !== DocumentStatus::Analyzing
                    || $version->analysis_seed !== $seed
                ) {
                    return false;
                }

                /*
                 * Never silently erase a previously surfaced safety warning.
                 * Deterministic reanalysis may add flags but cannot remove them.
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

                return true;
            },
        );

        if (! $completed) {
            return;
        }

        Log::info('document.analysis_completed', [
            'document_version_id' => $id,
            'analyzer_name' => $this->analyzer->name(),
            'analyzer_version' => $this->analyzer->version(),
            'analysis_seed' => $seed,
        ]);
    }

    /**
     * Record terminal failure after the queue exhausts its attempts.
     */
    public function markFailed(int $id): void
    {
        DocumentVersion::query()
            ->whereKey($id)
            ->where(
                'status',
                DocumentStatus::Analyzing->value,
            )
            ->update([
                'status' => DocumentStatus::AnalysisFailed->value,
                'analysis_completed_at' => null,
                'failure_code' => 'analysis_failed',
                'failure_message' => 'The document analysis could not be completed.',
                'updated_at' => now(),
            ]);
    }
}
