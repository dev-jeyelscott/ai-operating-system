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
    public function __construct(private DocumentParser $documentParser) {}

    /** @throws Throwable */
    public function handle(int $id): void
    {
        $version = DB::transaction(function () use ($id): ?DocumentVersion {
            $version = DocumentVersion::query()->lockForUpdate()->findOrFail($id);
            if ($version->status === DocumentStatus::ScanApproved) {
                $version->beginParsing('plain-text-mvp', '1.0.0');

                return $version->fresh();
            }

            return $version->status === DocumentStatus::Parsing ? $version : null;
        });
        if (! $version instanceof DocumentVersion) {
            return;
        }

        Log::info('document.parsing_started', ['document_version_id' => $id]);
        $parsed = $this->documentParser->parse($version);

        DB::transaction(function () use ($id, $parsed): void {
            $version = DocumentVersion::query()->lockForUpdate()->findOrFail($id);
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
        Log::info('document.parsed', ['document_version_id' => $id]);
    }

    public function markFailed(int $id): void
    {
        DocumentVersion::query()->whereKey($id)->where('status', DocumentStatus::Parsing->value)->update([
            'status' => DocumentStatus::ParseFailed->value,
            'failure_code' => 'parse_failed',
            'failure_message' => 'The document could not be parsed.',
            'updated_at' => now(),
        ]);
    }
}
