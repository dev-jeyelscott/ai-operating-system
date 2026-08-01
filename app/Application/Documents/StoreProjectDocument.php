<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\Data\InspectedDocumentUpload;
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
use RuntimeException;
use Throwable;

/**
 * Stores a securely inspected file and records its immutable initial version.
 */
final class StoreProjectDocument
{
    /**
     * Create the project-document storage application service.
     */
    public function __construct(
        private readonly DocumentUploadInspector $uploadInspector,
        private readonly RecordDocumentLifecycleEvent $events,
        private readonly TransactionManager $transactions,
    ) {}

    /**
     * Inspect, store, and begin processing one project document.
     *
     * @throws Throwable
     */
    public function handle(
        Organization $organization,
        Project $project,
        string $title,
        ?string $documentClass,
        UploadedFile $uploadedFile,
        ?AuditContext $auditContext = null,
    ): Document {
        $auditContext ??= AuditContext::system(
            actorId: 'document-upload-command',
        );

        /*
         * Repeat inspection here even when the HTTP request already validated
         * the file. This protects CLI, API, worker, and future internal callers.
         */
        $inspected = $this->uploadInspector->inspect($uploadedFile);

        $disk = (string) config('filesystems.artifact');
        $directory = sprintf(
            'documents/organizations/%d/projects/%d',
            $organization->id,
            $project->id,
        );
        $storedPath = null;

        try {
            $storedPath = $uploadedFile->storeAs(
                $directory,
                (string) Str::ulid(),
                $disk,
            );

            if (! is_string($storedPath) || $storedPath === '') {
                throw new RuntimeException(
                    'The document could not be stored.',
                );
            }

            return $this->transactions->run(function () use (
                $project,
                $title,
                $documentClass,
                $disk,
                $storedPath,
                $inspected,
                $auditContext,
            ): Document {
                $document = Document::query()->create([
                    'project_id' => $project->id,
                    'title' => $title,
                    'document_class' => $documentClass,
                ]);

                $documentVersion = $this->createVersion(
                    document: $document,
                    inspected: $inspected,
                    disk: $disk,
                    storedPath: $storedPath,
                );

                $uploadedEvent = $this->events->version(
                    version: $documentVersion,
                    eventType: AuditEventType::DocumentUploaded,
                    context: $auditContext,
                    metadata: [
                        'source' => 'initial_upload',
                        'previous_status' => null,
                        'new_status' => DocumentStatus::Quarantined->value,
                    ],
                );

                ScanDocumentVersionJob::dispatch(
                    documentVersionId: $documentVersion->id,
                    correlationId: $auditContext->correlationId,
                    causationId: $uploadedEvent->eventId,
                    executionId: $auditContext->executionId,
                )->afterCommit();

                return $document;
            });
        } catch (Throwable $exception) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk($disk)->delete($storedPath);
            }

            throw $exception;
        }
    }

    /**
     * Create the immutable first version from inspected metadata.
     */
    private function createVersion(
        Document $document,
        InspectedDocumentUpload $inspected,
        string $disk,
        string $storedPath,
    ): DocumentVersion {
        return DocumentVersion::query()->create([
            'document_id' => $document->id,
            'version' => 1,
            'original_filename' => $inspected->originalFilename,
            'media_type' => $inspected->mediaType,
            'byte_size' => $inspected->byteSize,
            'storage_disk' => $disk,
            'storage_path' => $storedPath,
            'checksum_sha256' => $inspected->checksumSha256,
            'status' => DocumentStatus::Quarantined,
            'classification' => DocumentClassification::Unclassified,
        ]);
    }
}
