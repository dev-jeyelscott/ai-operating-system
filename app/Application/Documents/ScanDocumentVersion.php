<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Contracts\MalwareScanner;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Documents\MalwareScanResult;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves one quarantined document through the malware scan gate.
 */
final readonly class ScanDocumentVersion
{
    public function __construct(private MalwareScanner $malwareScanner) {}

    /**
     * @throws Throwable
     */
    public function handle(int $documentVersionId): void
    {
        $documentVersion = DB::transaction(function () use (
            $documentVersionId,
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

            if ($documentVersion->status !== DocumentStatus::Quarantined) {
                return null;
            }

            $documentVersion->forceFill([
                'status' => DocumentStatus::ScanPending,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            return $documentVersion->fresh();
        });

        if (! $documentVersion instanceof DocumentVersion) {
            return;
        }

        try {
            $result = $this->malwareScanner->scan($documentVersion);
        } catch (Throwable $exception) {
            $this->markFailed($documentVersionId);

            throw $exception;
        }

        DB::transaction(function () use (
            $documentVersionId,
            $result,
        ): void {
            $documentVersion = DocumentVersion::query()
                ->lockForUpdate()
                ->findOrFail($documentVersionId);

            if ($documentVersion->status !== DocumentStatus::ScanPending) {
                return;
            }

            if ($result === MalwareScanResult::Clean) {
                $documentVersion->forceFill([
                    'status' => DocumentStatus::ScanApproved,
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();

                return;
            }

            $documentVersion->forceFill([
                'status' => DocumentStatus::Quarantined,
                'failure_code' => 'malware_detected',
                'failure_message' => 'The file remains quarantined after the malware scan.',
            ])->save();
        });
    }

    public function markFailed(int $documentVersionId): void
    {
        DocumentVersion::query()
            ->whereKey($documentVersionId)
            ->where('status', DocumentStatus::ScanPending)
            ->update([
                'status' => DocumentStatus::ScanFailed,
                'failure_code' => 'scan_unavailable',
                'failure_message' => 'The malware scan could not be completed.',
                'updated_at' => now(),
            ]);
    }
}
