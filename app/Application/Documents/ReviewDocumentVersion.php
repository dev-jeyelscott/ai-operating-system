<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Audit\Data\AuditContext;
use App\Domain\Audit\AuditEventType;
use App\Domain\Documents\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Applies explicit review decisions while preserving version authority.
 */
final class ReviewDocumentVersion
{
    public function __construct(
        private RecordDocumentLifecycleEvent $events,
    ) {}

    /**
     * Approve a fully analyzed version.
     *
     * For a replacement, the previous approved version is superseded in the
     * same transaction that approves the successor.
     */
    public function approve(
        Document $document,
        DocumentVersion $version,
        AuditContext $auditContext,
    ): void {
        DB::transaction(
            function () use ($document, $version, $auditContext): void {
                $lockedDocument = $this->lockedDocument(
                    $document,
                );

                $candidate = $this->lockedVersion(
                    document: $lockedDocument,
                    version: $version,
                );

                if (! $candidate->isReadyForReview()) {
                    throw new LogicException(
                        'Only a successfully analyzed document version can be approved.',
                    );
                }

                if (
                    $candidate->supersedes_document_version_id
                    === null
                ) {
                    $this->approveInitialVersion(
                        document: $lockedDocument,
                        candidate: $candidate,
                    );

                    $this->events->version(
                        version: $candidate,
                        eventType: AuditEventType::DocumentVersionApproved,
                        context: $auditContext,
                        metadata: [
                            'previous_status' => DocumentStatus::NeedsReview->value,
                            'new_status' => DocumentStatus::Approved->value,
                            'supersedes_document_version_id' => null,
                        ],
                    );

                    /*
                     * The initial-version invariant is still checked before
                     * the state transition.
                     */
                    return;
                }

                $previousApprovedVersion = $this->approveReplacementVersion(
                    document: $lockedDocument,
                    candidate: $candidate,
                );
                $approvedEvent = $this->events->version(
                    version: $candidate,
                    eventType: AuditEventType::DocumentVersionApproved,
                    context: $auditContext,
                    metadata: [
                        'previous_status' => DocumentStatus::NeedsReview->value,
                        'new_status' => DocumentStatus::Approved->value,
                        'supersedes_document_version_id' => $previousApprovedVersion->id,
                    ],
                );

                $this->events->version(
                    version: $previousApprovedVersion,
                    eventType: AuditEventType::DocumentVersionSuperseded,
                    context: $auditContext->causedBy($approvedEvent->eventId),
                    metadata: [
                        'previous_status' => DocumentStatus::Approved->value,
                        'new_status' => DocumentStatus::Superseded->value,
                        'successor_document_version_id' => $candidate->id,
                    ],
                );
            },
            attempts: 3,
        );
    }

    /**
     * Reject a fully analyzed version without changing current authority.
     */
    public function reject(
        Document $document,
        DocumentVersion $version,
        AuditContext $auditContext,
    ): void {
        DB::transaction(
            function () use ($document, $version, $auditContext): void {
                $lockedDocument = $this->lockedDocument(
                    $document,
                );

                $candidate = $this->lockedVersion(
                    document: $lockedDocument,
                    version: $version,
                );

                if (! $candidate->isReadyForReview()) {
                    throw new LogicException(
                        'Only a successfully analyzed document version can be rejected.',
                    );
                }

                $candidate->forceFill([
                    'status' => DocumentStatus::Rejected,
                ])->save();

                $this->events->version(
                    version: $candidate,
                    eventType: AuditEventType::DocumentVersionRejected,
                    context: $auditContext,
                    metadata: [
                        'previous_status' => DocumentStatus::NeedsReview->value,
                        'new_status' => DocumentStatus::Rejected->value,
                    ],
                );
            },
            attempts: 3,
        );
    }

    /**
     * Approve an initial version only when no other authority exists.
     */
    private function approveInitialVersion(
        Document $document,
        DocumentVersion $candidate,
    ): void {
        $approvedVersionExists = $document
            ->versions()
            ->where(
                'status',
                DocumentStatus::Approved->value,
            )
            ->where('id', '<>', $candidate->id)
            ->lockForUpdate()
            ->exists();

        if ($approvedVersionExists) {
            throw new LogicException(
                'This document already has an approved version.',
            );
        }

        $candidate->forceFill([
            'status' => DocumentStatus::Approved,
        ])->save();
    }

    /**
     * Atomically replace the exact approved predecessor.
     */
    private function approveReplacementVersion(
        Document $document,
        DocumentVersion $candidate,
    ): DocumentVersion {
        $previousApprovedVersion = DocumentVersion::query()
            ->whereKey(
                $candidate->supersedes_document_version_id,
            )
            ->where('document_id', $document->id)
            ->lockForUpdate()
            ->first();

        if (
            ! $previousApprovedVersion
            instanceof DocumentVersion
        ) {
            throw new LogicException(
                'The replacement predecessor could not be found.',
            );
        }

        if (
            $previousApprovedVersion->status
            !== DocumentStatus::Approved
        ) {
            throw new LogicException(
                'The replacement predecessor is no longer the approved version.',
            );
        }

        $approvedVersionIds = $document
            ->versions()
            ->where(
                'status',
                DocumentStatus::Approved->value,
            )
            ->lockForUpdate()
            ->pluck('id');

        if (
            $approvedVersionIds->count() !== 1
            || (int) $approvedVersionIds->first()
                !== $previousApprovedVersion->id
        ) {
            throw new LogicException(
                'The document does not have one unambiguous approved predecessor.',
            );
        }

        $previousApprovedVersion->forceFill([
            'status' => DocumentStatus::Superseded,
        ])->save();

        $candidate->forceFill([
            'status' => DocumentStatus::Approved,
        ])->save();

        return $previousApprovedVersion;
    }

    /**
     * Lock the document aggregate before locking individual versions.
     */
    private function lockedDocument(
        Document $document,
    ): Document {
        return Document::query()
            ->whereKey($document->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Lock a version while enforcing document ownership.
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
