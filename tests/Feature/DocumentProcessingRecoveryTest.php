<?php

declare(strict_types=1);

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\RetryDocumentVersionProcessing;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\AnalyzeDocumentVersionJob;
use App\Jobs\ParseDocumentVersionJob;
use App\Jobs\ScanDocumentVersionJob;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Queue;

test(
    'failed scans can be requeued without creating another version',
    function (): void {
        Queue::fake();

        $version = DocumentVersion::factory()->create([
            'status' => DocumentStatus::ScanFailed,
            'failure_code' => 'scan_failed',
            'failure_message' => 'Temporary scanner failure.',
        ]);

        $checksum = $version->checksum_sha256;

        app(RetryDocumentVersionProcessing::class)
            ->handle(
                $version,
                AuditContext::system(actorId: 'feature-test'),
            );

        expect($version->fresh())
            ->status->toBe(DocumentStatus::Quarantined)
            ->checksum_sha256->toBe($checksum)
            ->failure_code->toBeNull()
            ->failure_message->toBeNull()
            ->and(DocumentVersion::query()->count())
            ->toBe(1);

        Queue::assertPushed(
            ScanDocumentVersionJob::class,
            fn (
                ScanDocumentVersionJob $job,
            ): bool => $job->documentVersionId === $version->id,
        );
    },
);

test(
    'transient parser failures can be requeued without changing identity',
    function (): void {
        Queue::fake();

        $version = DocumentVersion::factory()->create([
            'status' => DocumentStatus::ParseFailed,
            'failure_code' => 'parse_failed',
            'failure_message' => 'Temporary parser failure.',
        ]);

        $checksum = $version->checksum_sha256;

        app(RetryDocumentVersionProcessing::class)
            ->handle(
                $version,
                AuditContext::system(actorId: 'feature-test'),
            );

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ScanApproved)
            ->checksum_sha256->toBe($checksum)
            ->failure_code->toBeNull()
            ->failure_message->toBeNull()
            ->and(DocumentVersion::query()->count())
            ->toBe(1);

        Queue::assertPushed(
            ParseDocumentVersionJob::class,
            fn (
                ParseDocumentVersionJob $job,
            ): bool => $job->documentVersionId === $version->id,
        );
    },
);

test(
    'permanent parser capability failures cannot be retried',
    function (): void {
        Queue::fake();

        $version = DocumentVersion::factory()->create([
            'original_filename' => 'architecture.pdf',
            'media_type' => 'application/pdf',
            'status' => DocumentStatus::ParseFailed,
            'failure_code' => 'unsupported_media_type',
            'failure_message' => 'No parser is registered for application/pdf.',
        ]);

        expect(
            fn (): null => app(
                RetryDocumentVersionProcessing::class,
            )->handle(
                $version,
                AuditContext::system(actorId: 'feature-test'),
            ),
        )->toThrow(
            LogicException::class,
            'Permanent document content failures cannot be retried.',
        );

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ParseFailed)
            ->failure_code->toBe('unsupported_media_type')
            ->failure_message->toBe(
                'No parser is registered for application/pdf.',
            );

        Queue::assertNothingPushed();
    },
);

test('completed processing cannot be retried', function (): void {
    Queue::fake();

    $version = DocumentVersion::factory()
        ->classified()
        ->create();

    expect(
        fn (): null => app(
            RetryDocumentVersionProcessing::class,
        )->handle(
            $version,
            AuditContext::system(actorId: 'feature-test'),
        ),
    )->toThrow(
        LogicException::class,
        'Only failed document processing can be retried.',
    );

    expect($version->fresh()->status)
        ->toBe(DocumentStatus::NeedsReview);

    Queue::assertNothingPushed();
});

test(
    'failed analysis can be requeued without losing safety flags',
    function (): void {
        Queue::fake();

        $version = DocumentVersion::factory()
            ->analysisFailed()
            ->create([
                'analysis_flags' => ['prompt_injection'],
            ]);

        $checksum = $version->checksum_sha256;

        app(RetryDocumentVersionProcessing::class)
            ->handle(
                $version,
                AuditContext::system(actorId: 'feature-test'),
            );

        expect($version->fresh())
            ->status->toBe(DocumentStatus::AnalysisPending)
            ->checksum_sha256->toBe($checksum)
            ->analysis_flags->toBe(['prompt_injection'])
            ->analysis_started_at->toBeNull()
            ->analysis_completed_at->toBeNull()
            ->failure_code->toBeNull()
            ->failure_message->toBeNull()
            ->and(DocumentVersion::query()->count())
            ->toBe(1);

        Queue::assertPushed(
            AnalyzeDocumentVersionJob::class,
            fn (
                AnalyzeDocumentVersionJob $job,
            ): bool => $job->documentVersionId === $version->id,
        );
    },
);
