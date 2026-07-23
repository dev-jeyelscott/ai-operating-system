<?php

declare(strict_types=1);

use App\Application\Documents\ParseDocumentVersion;
use App\Domain\Documents\DocumentStatus;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('documents');
});

test('a scan-approved text document is parsed idempotently', function (): void {
    $version = DocumentVersion::factory()->create([
        'media_type' => 'text/plain', 'storage_disk' => 'documents',
        'storage_path' => 'documents/requirements.txt', 'status' => DocumentStatus::ScanApproved,
    ]);
    Storage::disk('documents')->put($version->storage_path, 'Build the document center.');

    app(ParseDocumentVersion::class)->handle($version->id);
    app(ParseDocumentVersion::class)->handle($version->id);

    expect($version->fresh())
        ->status->toBe(DocumentStatus::Parsed)
        ->parsed_content->toBe('Build the document center.')
        ->parser_name->toBe('plain-text-mvp')
        ->parser_version->toBe('1.0.0')
        ->parsed_at->not->toBeNull();
});

test('a quarantined document cannot enter parsing', function (): void {
    $version = DocumentVersion::factory()->create(['status' => DocumentStatus::Quarantined]);
    app(ParseDocumentVersion::class)->handle($version->id);
    expect($version->fresh())->status->toBe(DocumentStatus::Quarantined);
});

test('a failed parser stays parsing for retry then records terminal failure', function (): void {
    $version = DocumentVersion::factory()->create([
        'media_type' => 'application/pdf', 'status' => DocumentStatus::ScanApproved,
    ]);
    expect(fn (): null => app(ParseDocumentVersion::class)->handle($version->id))
        ->toThrow(RuntimeException::class);
    expect($version->fresh()->status)->toBe(DocumentStatus::Parsing);
    app(ParseDocumentVersion::class)->markFailed($version->id);
    expect($version->fresh())->status->toBe(DocumentStatus::ParseFailed)->failure_code->toBe('parse_failed');
});
