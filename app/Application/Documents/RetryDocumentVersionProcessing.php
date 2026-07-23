<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Domain\Documents\DocumentStatus;
use App\Jobs\ParseDocumentVersionJob;
use App\Jobs\ScanDocumentVersionJob;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Safely requeues a failed processing stage without creating a new version.
 */
final class RetryDocumentVersionProcessing
{
    public function handle(DocumentVersion $version): void
    {
        $job = DB::transaction(function () use ($version): string {
            $lockedVersion = DocumentVersion::query()
                ->lockForUpdate()
                ->findOrFail($version->id);

            return match ($lockedVersion->status) {
                DocumentStatus::ScanFailed => $this->retryScan($lockedVersion),
                DocumentStatus::ParseFailed => $this->retryParsing($lockedVersion),
                default => throw new LogicException(
                    'Only failed document processing can be retried.',
                ),
            };
        });

        if ($job === 'scan') {
            ScanDocumentVersionJob::dispatch($version->id)->afterCommit();

            return;
        }

        ParseDocumentVersionJob::dispatch($version->id)->afterCommit();
    }

    private function retryScan(DocumentVersion $version): string
    {
        $version->forceFill([
            'status' => DocumentStatus::Quarantined,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        return 'scan';
    }

    private function retryParsing(DocumentVersion $version): string
    {
        $version->forceFill([
            'status' => DocumentStatus::ScanApproved,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        return 'parse';
    }
}
