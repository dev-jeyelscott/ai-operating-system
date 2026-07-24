<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Domain\Documents\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Applies explicit review decisions while preserving every prior revision.
 */
final class ReviewDocumentVersion
{
    /**
     * Approve a fully analyzed document version.
     */
    public function approve(
        Document $document,
        DocumentVersion $version,
    ): void {
        $this->review(
            $document,
            $version,
            DocumentStatus::Approved,
        );
    }

    /**
     * Reject a fully analyzed document version.
     */
    public function reject(
        Document $document,
        DocumentVersion $version,
    ): void {
        $this->review(
            $document,
            $version,
            DocumentStatus::Rejected,
        );
    }

    /**
     * Supersede an approved version and create an unapproved successor.
     *
     * Because the successor has identical immutable file identity, its completed
     * deterministic analysis is copied and it starts at NeedsReview.
     */
    public function supersede(
        Document $document,
        DocumentVersion $version,
    ): DocumentVersion {
        return DB::transaction(
            function () use (
                $document,
                $version,
            ): DocumentVersion {
                $document = Document::query()
                    ->lockForUpdate()
                    ->findOrFail($document->id);

                $source = $this->lockedVersion(
                    $document,
                    $version,
                );

                if (
                    $source->status
                    !== DocumentStatus::Approved
                ) {
                    throw new LogicException(
                        'Only an approved document version can be superseded.',
                    );
                }

                $latestVersion = $document->versions()
                    ->lockForUpdate()
                    ->orderByDesc('version')
                    ->firstOrFail();

                $source->forceFill([
                    'status' => DocumentStatus::Superseded,
                ])->save();

                return DocumentVersion::query()->create([
                    'document_id' => $document->id,
                    'version' => $latestVersion->version + 1,
                    'original_filename' => $source->original_filename,
                    'media_type' => $source->media_type,
                    'byte_size' => $source->byte_size,
                    'storage_disk' => $source->storage_disk,
                    'storage_path' => $source->storage_path,
                    'checksum_sha256' => $source->checksum_sha256,
                    'status' => DocumentStatus::NeedsReview,
                    'classification' => $source->classification,
                    'parser_name' => $source->parser_name,
                    'parser_version' => $source->parser_version,
                    'parsing_started_at' => $source->parsing_started_at,
                    'parsed_at' => $source->parsed_at,
                    'parsed_content' => $source->parsed_content,
                    'analyzer_name' => $source->analyzer_name,
                    'analyzer_version' => $source->analyzer_version,
                    'analysis_seed' => $source->analysis_seed,
                    'analysis_started_at' => $source->analysis_started_at,
                    'analysis_completed_at' => $source->analysis_completed_at,
                    'analysis_summary' => $source->analysis_summary,
                    'analysis_conflicts' => $source->analysis_conflicts,
                    'analysis_gaps' => $source->analysis_gaps,
                    'analysis_flags' => $source->analysis_flags,
                    'failure_code' => null,
                    'failure_message' => null,
                    'supersedes_document_version_id' => $source->id,
                ]);
            },
        );
    }

    /**
     * Apply an authorized review decision inside a locked transaction.
     */
    private function review(
        Document $document,
        DocumentVersion $version,
        DocumentStatus $decision,
    ): void {
        DB::transaction(
            function () use (
                $document,
                $version,
                $decision,
            ): void {
                $source = $this->lockedVersion(
                    $document,
                    $version,
                );

                if (! $source->isReadyForReview()) {
                    throw new LogicException(
                        'Only a successfully analyzed document version can be reviewed.',
                    );
                }

                $source->forceFill([
                    'status' => $decision,
                ])->save();
            },
        );
    }

    /**
     * Lock the requested version while enforcing document ownership.
     */
    private function lockedVersion(
        Document $document,
        DocumentVersion $version,
    ): DocumentVersion {
        return DocumentVersion::query()
            ->whereKey($version->id)
            ->where('document_id', $document->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
