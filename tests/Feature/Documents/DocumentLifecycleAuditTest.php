<?php

declare(strict_types=1);

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\ScanDocumentVersion;
use App\Application\Documents\StoreProjectDocument;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\ScanDocumentVersionJob;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('document upload records attributable redacted lifecycle evidence', function (): void {
    config()->set('filesystems.artifact', 'documents');

    Storage::fake('documents');
    Queue::fake();

    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    $document = app(StoreProjectDocument::class)->handle(
        organization: $organization,
        project: $project,
        title: 'Architecture',
        documentClass: 'architecture',
        uploadedFile: UploadedFile::fake()->create(
            'customer-secret-api-key.md',
            4,
            'text/markdown',
        ),
        auditContext: AuditContext::user(
            userId: $user->id,
            correlationId: 'document-upload-001',
        ),
    );

    $version = $document->versions()->sole();
    $event = AuditEvent::query()
        ->where('event_type', AuditEventType::DocumentUploaded->value)
        ->sole();

    expect($event)
        ->organization_id->toBe($organization->id)
        ->project_id->toBe($project->id)
        ->actor_type->toBe(AuditActorType::User)
        ->actor_id->toBe((string) $user->id)
        ->subject_type->toBe(AuditSubjectType::DocumentVersion)
        ->subject_id->toBe((string) $version->id)
        ->correlation_id->toBe('document-upload-001')
        ->schema_version->toBe(1);

    expect($event->metadata)
        ->toHaveKey('checksum_sha256')
        ->not->toHaveKey('original_filename')
        ->not->toHaveKey('storage_path')
        ->not->toHaveKey('parsed_content');

    Queue::assertPushed(
        ScanDocumentVersionJob::class,
        fn (ScanDocumentVersionJob $job): bool => $job->documentVersionId === $version->id
            && $job->correlationId === 'document-upload-001'
            && $job->causationId === $event->event_id,
    );
});

test('duplicate scan delivery does not append duplicate transition evidence', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $document = Document::factory()->for($project)->create();
    $version = DocumentVersion::factory()->for($document)->create([
        'status' => DocumentStatus::Quarantined,
    ]);

    $scanner = app(ScanDocumentVersion::class);
    $context = AuditContext::system(
        actorId: 'document-scan-worker',
        correlationId: 'scan-replay-001',
        causationId: 'upload-event-001',
    );

    $scanner->handle($version->id, $context);
    $scanner->handle($version->id, $context);

    expect(AuditEvent::query()
        ->where('event_type', AuditEventType::DocumentScanStarted->value)
        ->count())->toBe(1);
    expect(AuditEvent::query()
        ->where('event_type', AuditEventType::DocumentScanCompleted->value)
        ->count())->toBe(1);
});
