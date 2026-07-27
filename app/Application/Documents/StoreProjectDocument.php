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
use RuntimeException;
use Throwable;

/**
 * Stores a received file privately and records its immutable initial version.
 */
final class StoreProjectDocument
{
    /**
     * Create the project-document storage application service.
     */
    public function __construct(
        private readonly RecordDocumentLifecycleEvent $events,
        private readonly TransactionManager $transactions,
    ) {}

    /**
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
        $auditContext ??= AuditContext::system(actorId: 'document-upload-command');
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
                throw new RuntimeException('The document could not be stored.');
            }

            $checksum = hash_file('sha256', $uploadedFile->getRealPath());

            if (! is_string($checksum)) {
                throw new RuntimeException('The document checksum could not be calculated.');
            }

            return $this->transactions->run(function () use (
                $project,
                $title,
                $documentClass,
                $uploadedFile,
                $disk,
                $storedPath,
                $checksum,
                $auditContext,
            ): Document {
                $document = Document::query()->create([
                    'project_id' => $project->id,
                    'title' => $title,
                    'document_class' => $documentClass,
                ]);

                $documentVersion = DocumentVersion::query()->create([
                    'document_id' => $document->id,
                    'version' => 1,
                    'original_filename' => $this->originalFilename($uploadedFile),
                    'media_type' => $uploadedFile->getMimeType(),
                    'byte_size' => $uploadedFile->getSize(),
                    'storage_disk' => $disk,
                    'storage_path' => $storedPath,
                    'checksum_sha256' => $checksum,
                    'status' => DocumentStatus::Quarantined,
                    'classification' => DocumentClassification::Unclassified,
                ]);

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

    private function originalFilename(UploadedFile $uploadedFile): string
    {
        $filename = trim(basename($uploadedFile->getClientOriginalName()));

        return Str::limit(
            $filename !== '' ? $filename : 'document',
            255,
            '',
        );
    }
}
