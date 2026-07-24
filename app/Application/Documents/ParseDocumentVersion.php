<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Contracts\DocumentParser;
use App\Domain\Documents\DocumentStatus;
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
     * Permanent capability mismatches are recorded immediately and are not
     * thrown back to the queue as retryable failures.
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

                if ($version->status === DocumentStatus::ScanApproved) {
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

        DB::transaction(function () use ($id, $parsed): void {
            $version = DocumentVersion::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($version->status !== DocumentStatus::Parsing) {
                return;
            }

            $version->forceFill([
                'status' => DocumentStatus::Parsed,
                'parser_name' => $parsed->parserName,
                'parser_version' => $parsed->parserVersion,
                'parsed_at' => now(),
                'parsed_content' => $parsed->content,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();
        });

        Log::info('document.parsed', [
            'document_version_id' => $id,
        ]);
    }

    /**
     * Record a terminal retryable parser failure after attempts are exhausted.
     */
    public function markFailed(int $id): void
    {
        DocumentVersion::query()
            ->whereKey($id)
            ->where('status', DocumentStatus::Parsing->value)
            ->update([
                'status' => DocumentStatus::ParseFailed->value,
                'failure_code' => 'parse_failed',
                'failure_message' => 'The document could not be parsed.',
                'updated_at' => now(),
            ]);
    }

    /**
     * Record a permanent media-capability mismatch without queue retries.
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
                implode(', ', $this->documentParser->supportedMediaTypes()),
            ),
        ])->save();

        Log::warning('document.parsing_rejected', [
            'document_version_id' => $version->id,
            'media_type' => $version->media_type,
            'failure_code' => 'unsupported_media_type',
        ]);
    }
}
