<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Audit\Data\AuditContext;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditEventType;
use App\Domain\Documents\DocumentClassification;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\ScanDocumentVersionJob;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Stores a genuine replacement revision without changing current authority.
 */
final readonly class StoreReplacementDocumentVersion
{
    public function __construct(
        private RecordDocumentLifecycleEvent $events,
        private TransactionManager $transactions
    ) {}

    /**
     * Store a new immutable replacement file and begin its safety lifecycle.
     *
     * The current approved version remains authoritative until the replacement
     * completes processing and is explicitly approved.
     *
     * @throws Throwable
     */
    public function handle(
        Organization $organization,
        Project $project,
        Document $document,
        DocumentVersion $approvedVersion,
        UploadedFile $uploadedFile,
        ?AuditContext $auditContext = null,
    ): DocumentVersion {
        $auditContext ??= AuditContext::system(actorId: 'document-replacement-command');
        $this->ensureRouteScope(
            organization: $organization,
            project: $project,
            document: $document,
            approvedVersion: $approvedVersion,
        );

        $disk = (string) config('filesystems.artifact');

        $directory = sprintf(
            'documents/organizations/%d/projects/%d/documents/%d',
            $organization->id,
            $project->id,
            $document->id,
        );

        $mediaType = $uploadedFile->getMimeType();
        $byteSize = $uploadedFile->getSize();
        $realPath = $uploadedFile->getRealPath();

        if (! is_string($mediaType) || trim($mediaType) === '') {
            throw new RuntimeException(
                'The replacement media type could not be determined.',
            );
        }

        if (! is_int($byteSize) || $byteSize < 1) {
            throw new RuntimeException(
                'The replacement file size could not be determined.',
            );
        }

        if (! is_string($realPath) || $realPath === '') {
            throw new RuntimeException(
                'The replacement upload is unavailable.',
            );
        }

        $checksum = hash_file('sha256', $realPath);

        if (! is_string($checksum)) {
            throw new RuntimeException(
                'The replacement checksum could not be calculated.',
            );
        }

        $storedPath = null;

        try {
            $storedPath = $uploadedFile->storeAs(
                $directory,
                (string) Str::ulid(),
                $disk,
            );

            if (! is_string($storedPath) || $storedPath === '') {
                throw new RuntimeException(
                    'The replacement document could not be stored.',
                );
            }

            return $this->transactions->run(
                function () use (
                    $project,
                    $document,
                    $approvedVersion,
                    $uploadedFile,
                    $mediaType,
                    $byteSize,
                    $disk,
                    $storedPath,
                    $checksum,
                    $auditContext,
                ): DocumentVersion {
                    /*
                     * Lock the parent aggregate before allocating the next
                     * monotonic version number.
                     */
                    $lockedDocument = Document::query()
                        ->whereKey($document->id)
                        ->where('project_id', $project->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedApprovedVersion = DocumentVersion::query()
                        ->whereKey($approvedVersion->id)
                        ->where('document_id', $lockedDocument->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        $lockedApprovedVersion->status
                        !== DocumentStatus::Approved
                    ) {
                        throw new LogicException(
                            'Only the current approved document version can be replaced.',
                        );
                    }

                    /*
                     * Refuse to manufacture a replacement when authority is
                     * already ambiguous. Exactly one approved source is required.
                     */
                    $approvedVersionIds = $lockedDocument
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
                        !== $lockedApprovedVersion->id
                    ) {
                        throw new LogicException(
                            'The document does not have one unambiguous approved version.',
                        );
                    }

                    $nextVersion = (
                        (int) $lockedDocument
                            ->versions()
                            ->max('version')
                    ) + 1;

                    $replacement = DocumentVersion::query()->create([
                        'document_id' => $lockedDocument->id,
                        'version' => $nextVersion,
                        'original_filename' => $this->originalFilename(
                            $uploadedFile,
                        ),
                        'media_type' => $mediaType,
                        'byte_size' => $byteSize,
                        'storage_disk' => $disk,
                        'storage_path' => $storedPath,
                        'checksum_sha256' => $checksum,
                        'status' => DocumentStatus::Quarantined,
                        'classification' => DocumentClassification::Unclassified,
                        'parser_name' => null,
                        'parser_version' => null,
                        'parsing_started_at' => null,
                        'parsed_at' => null,
                        'parsed_content' => null,
                        'analyzer_name' => null,
                        'analyzer_version' => null,
                        'analysis_seed' => null,
                        'analysis_started_at' => null,
                        'analysis_completed_at' => null,
                        'analysis_summary' => null,
                        'analysis_conflicts' => null,
                        'analysis_gaps' => null,
                        'analysis_flags' => null,
                        'failure_code' => null,
                        'failure_message' => null,
                        'supersedes_document_version_id' => $lockedApprovedVersion->id,
                    ]);

                    $uploadedEvent = $this->events->version(
                        version: $replacement,
                        eventType: AuditEventType::DocumentReplacementUploaded,
                        context: $auditContext,
                        metadata: [
                            'source' => 'replacement_upload',
                            'previous_status' => null,
                            'new_status' => DocumentStatus::Quarantined->value,
                            'supersedes_document_version_id' => $lockedApprovedVersion->id,
                        ],
                    );

                    ScanDocumentVersionJob::dispatch(
                        documentVersionId: $replacement->id,
                        correlationId: $auditContext->correlationId,
                        causationId: $uploadedEvent->eventId,
                        executionId: $auditContext->executionId,
                    )->afterCommit();

                    return $replacement;
                },
            );
        } catch (Throwable $exception) {
            if (is_string($storedPath) && $storedPath !== '') {
                $this->deleteUnreferencedObject(
                    disk: $disk,
                    storedPath: $storedPath,
                );
            }

            throw $exception;
        }
    }

    /**
     * Verify that every route model belongs to the same tenant hierarchy.
     */
    private function ensureRouteScope(
        Organization $organization,
        Project $project,
        Document $document,
        DocumentVersion $approvedVersion,
    ): void {
        if (
            (int) $project->organization_id !== $organization->id
            || (int) $document->project_id !== $project->id
            || (int) $approvedVersion->document_id !== $document->id
        ) {
            throw new LogicException(
                'The requested document version does not belong to this project.',
            );
        }
    }

    /**
     * Delete a stored upload only when no persisted version references it.
     *
     * If database availability prevents proving that the object is unreferenced,
     * retain the object. An orphan is safer than deleting committed evidence.
     */
    private function deleteUnreferencedObject(
        string $disk,
        string $storedPath,
    ): void {
        try {
            $isReferenced = DocumentVersion::query()
                ->where('storage_disk', $disk)
                ->where('storage_path', $storedPath)
                ->exists();
        } catch (Throwable) {
            return;
        }

        if (! $isReferenced) {
            Storage::disk($disk)->delete($storedPath);
        }
    }

    /**
     * Return a normalized, bounded client filename for audit display.
     */
    private function originalFilename(
        UploadedFile $uploadedFile,
    ): string {
        $filename = trim(
            basename($uploadedFile->getClientOriginalName()),
        );

        return Str::limit(
            $filename !== '' ? $filename : 'document',
            255,
            '',
        );
    }
}
