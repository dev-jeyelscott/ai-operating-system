<?php

declare(strict_types=1);

use App\Application\Documents\RetryDocumentVersionProcessing;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\ParseDocumentVersionJob;
use App\Jobs\ScanDocumentVersionJob;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Queue;

test('failed scans can be requeued without creating another version', function (): void {
    Queue::fake();
    $version = DocumentVersion::factory()->create(['status' => DocumentStatus::ScanFailed]);

    app(RetryDocumentVersionProcessing::class)->handle($version);

    expect($version->fresh())->status->toBe(DocumentStatus::Quarantined)
        ->and(DocumentVersion::query()->count())->toBe(1);
    Queue::assertPushed(ScanDocumentVersionJob::class);
});

test('failed parsing can be requeued without changing file identity', function (): void {
    Queue::fake();
    $version = DocumentVersion::factory()->create(['status' => DocumentStatus::ParseFailed]);
    $checksum = $version->checksum_sha256;

    app(RetryDocumentVersionProcessing::class)->handle($version);

    expect($version->fresh())->status->toBe(DocumentStatus::ScanApproved)
        ->checksum_sha256->toBe($checksum)
        ->and(DocumentVersion::query()->count())->toBe(1);
    Queue::assertPushed(ParseDocumentVersionJob::class);
});

test('completed processing cannot be retried', function (): void {
    $version = DocumentVersion::factory()->create(['status' => DocumentStatus::Parsed]);

    expect(fn (): null => app(RetryDocumentVersionProcessing::class)->handle($version))
        ->toThrow(LogicException::class, 'Only failed document processing can be retried.');
});
