<?php

declare(strict_types=1);

use App\Application\Documents\RetryDocumentVersionProcessing;
use App\Domain\Documents\DocumentStatus;
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
        ]);

        app(RetryDocumentVersionProcessing::class)
            ->handle($version);

        expect($version->fresh())
            ->status->toBe(DocumentStatus::Quarantined)
            ->and(DocumentVersion::query()->count())->toBe(1);

        Queue::assertPushed(ScanDocumentVersionJob::class);
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
            ->handle($version);

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ScanApproved)
            ->checksum_sha256->toBe($checksum)
            ->failure_code->toBeNull()
            ->and(DocumentVersion::query()->count())->toBe(1);

        Queue::assertPushed(ParseDocumentVersionJob::class);
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
            )->handle($version),
        )->toThrow(
            LogicException::class,
            'Permanent parser capability failures cannot be retried.',
        );

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ParseFailed)
            ->failure_code->toBe('unsupported_media_type');

        Queue::assertNothingPushed();
    },
);

test('completed processing cannot be retried', function (): void {
    Queue::fake();

    $version = DocumentVersion::factory()->create([
        'status' => DocumentStatus::Parsed,
    ]);

    expect(
        fn (): null => app(
            RetryDocumentVersionProcessing::class,
        )->handle($version),
    )->toThrow(
        LogicException::class,
        'Only failed document processing can be retried.',
    );

    Queue::assertNothingPushed();
});
