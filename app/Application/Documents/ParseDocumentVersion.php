<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Contracts\DocumentParser;
use App\Domain\Documents\DocumentClassification;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\AnalyzeDocumentVersionJob;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class ParseDocumentVersion
{
    public function __construct(
        private DocumentParser $documentParser,
    ) {}

    /**
     * Parse one scan-approved document version idempotently.
     *
     * Successful parsing persists AnalysisPending and dispatches the unique
     * analysis job only after the parse transaction commits.
     *
     * @throws Throwable
     */
    public function handle(int $id): void
    {
        $version = DB::transaction(
            function () use ($id): ?DocumentVersion {
                $version = DocumentVersion::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                if (! in_array($version->status, [
                    DocumentStatus::ScanApproved,
                    DocumentStatus::Parsing,
                ], true)) {
                    return null;
                }

                if (! $this->documentParser->supports(
                    $version->media_type,
                )) {
                    $this->markUnsupportedMediaType($version);

                    return null;
                }

                if (
                    $version->status
                    === DocumentStatus::ScanApproved
                ) {
                    $version->beginParsing(
                        $this->documentParser->name(),
                        $this->documentParser->version(),
                    );

                    return $version->fresh();
                }

                return $version;
            },
        );

        if (! $version instanceof DocumentVersion) {
            return;
        }

        Log::info('document.parsing_started', [
            'document_version_id' => $id,
            'parser_name' => $this->documentParser->name(),
            'parser_version' => $this->documentParser->version(),
        ]);

        $parsed = $this->documentParser->parse($version);

        $analysisPending = DB::transaction(
            function () use ($id, $parsed): bool {
                $version = DocumentVersion::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                if (
                    $version->status
                    !== DocumentStatus::Parsing
                ) {
                    return false;
                }

                $version->forceFill([
                    'status' => DocumentStatus::AnalysisPending,
                    'classification' => DocumentClassification::Unclassified,
                    'parser_name' => $parsed->parserName,
                    'parser_version' => $parsed->parserVersion,
                    'parsed_at' => now(),
                    'parsed_content' => $parsed->content,
                    'analyzer_name' => null,
                    'analyzer_version' => null,
                    'analysis_seed' => null,
                    'analysis_started_at' => null,
                    'analysis_completed_at' => null,
                    'analysis_summary' => null,
                    'analysis_conflicts' => [],
                    'analysis_gaps' => [],
                    'analysis_flags' => [],
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();

                return true;
            },
        );

        if (! $analysisPending) {
            return;
        }

        AnalyzeDocumentVersionJob::dispatch($id)
            ->afterCommit();

        Log::info('document.parsed', [
            'document_version_id' => $id,
            'next_status' => DocumentStatus::AnalysisPending->value,
        ]);
    }

    /**
     * Record terminal parser failure after queue attempts are exhausted.
     */
    public function markFailed(int $id): void
    {
        DocumentVersion::query()
            ->whereKey($id)
            ->where(
                'status',
                DocumentStatus::Parsing->value,
            )
            ->update([
                'status' => DocumentStatus::ParseFailed->value,
                'failure_code' => 'parse_failed',
                'failure_message' => 'The document could not be parsed.',
                'updated_at' => now(),
            ]);
    }

    /**
     * Record an unsupported media type as a permanent parser failure.
     */
    private function markUnsupportedMediaType(
        DocumentVersion $version,
    ): void {
        $version->forceFill([
            'status' => DocumentStatus::ParseFailed,
            'parser_name' => null,
            'parser_version' => null,
            'parsing_started_at' => null,
            'parsed_at' => null,
            'parsed_content' => null,
            'failure_code' => 'unsupported_media_type',
            'failure_message' => sprintf(
                'No parser is registered for media type "%s". Supported media types: %s.',
                $version->media_type,
                implode(
                    ', ',
                    $this->documentParser->supportedMediaTypes(),
                ),
            ),
        ])->save();

        Log::warning('document.parsing_rejected', [
            'document_version_id' => $version->id,
            'media_type' => $version->media_type,
            'failure_code' => 'unsupported_media_type',
        ]);
    }
}
