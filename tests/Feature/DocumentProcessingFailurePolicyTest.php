<?php

declare(strict_types=1);

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\RetryDocumentVersionProcessing;
use App\Domain\Documents\DocumentProcessingFailureCode;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\ParseDocumentVersionJob;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Queue;

dataset('permanent document processing failures', [
    DocumentProcessingFailureCode::UnsupportedMediaType,
    DocumentProcessingFailureCode::UnsupportedExtension,
    DocumentProcessingFailureCode::DocumentTooLarge,
    DocumentProcessingFailureCode::ArchiveContentDetected,
    DocumentProcessingFailureCode::BinaryContentDetected,
    DocumentProcessingFailureCode::InvalidTextEncoding,
    DocumentProcessingFailureCode::UploadUnreadable,
]);

test(
    'permanent content failures do not expose or execute retry',
    function (
        DocumentProcessingFailureCode $failureCode,
    ): void {
        Queue::fake();

        $version = DocumentVersion::factory()->create([
            'status' => DocumentStatus::ParseFailed,
            'failure_code' => $failureCode->value,
            'failure_message' => 'The immutable input is invalid.',
        ]);

        expect($version->canRetryProcessing())->toBeFalse();

        expect(
            fn (): null => app(
                RetryDocumentVersionProcessing::class,
            )->handle(
                version: $version,
                auditContext: AuditContext::system(
                    actorId: 'failure-policy-test',
                ),
            ),
        )->toThrow(
            LogicException::class,
            'Permanent document content failures cannot be retried.',
        );

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ParseFailed)
            ->failure_code->toBe($failureCode->value);

        Queue::assertNothingPushed();
    },
)->with('permanent document processing failures');

test(
    'a transient storage read failure can be retried',
    function (): void {
        Queue::fake();

        $version = DocumentVersion::factory()->create([
            'status' => DocumentStatus::ParseFailed,
            'failure_code' => DocumentProcessingFailureCode::StorageReadFailed->value,
            'failure_message' => 'The stored document could not be read.',
        ]);

        expect($version->canRetryProcessing())->toBeTrue();

        app(RetryDocumentVersionProcessing::class)->handle(
            version: $version,
            auditContext: AuditContext::system(
                actorId: 'failure-policy-test',
            ),
        );

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ScanApproved)
            ->failure_code->toBeNull()
            ->failure_message->toBeNull();

        Queue::assertPushed(
            ParseDocumentVersionJob::class,
            fn (
                ParseDocumentVersionJob $job,
            ): bool => $job->documentVersionId === $version->id,
        );
    },
);
