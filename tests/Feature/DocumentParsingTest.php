<?php

declare(strict_types=1);

use App\Application\Documents\Contracts\DocumentParser;
use App\Application\Documents\ParseDocumentVersion;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\AnalyzeDocumentVersionJob;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('documents');
    Queue::fake();
});

dataset('supported document media types', [
    'plain text' => [
        'text/plain',
        'requirements.txt',
        'Build the document center.',
    ],
    'markdown' => [
        'text/markdown',
        'architecture.md',
        '# Architecture baseline',
    ],
]);

test(
    'every advertised media type reaches parsed idempotently',
    function (
        string $mediaType,
        string $filename,
        string $content,
    ): void {
        $parser = app(DocumentParser::class);

        expect($parser->supportedMediaTypes())
            ->toContain($mediaType);

        $version = DocumentVersion::factory()->create([
            'original_filename' => $filename,
            'media_type' => $mediaType,
            'storage_disk' => 'documents',
            'storage_path' => "documents/{$filename}",
            'status' => DocumentStatus::ScanApproved,
        ]);

        Storage::disk('documents')->put(
            $version->storage_path,
            $content,
        );

        app(ParseDocumentVersion::class)->handle($version->id);
        app(ParseDocumentVersion::class)->handle($version->id);

        expect($version->fresh())
            ->status->toBe(DocumentStatus::AnalysisPending)
            ->parsed_content->toBe($content)
            ->parser_name->toBe('plain-text-mvp')
            ->parser_version->toBe('1.0.0')
            ->parsed_at->not->toBeNull();

        Queue::assertPushed(
            AnalyzeDocumentVersionJob::class,
            1,
        );
    },
)->with('supported document media types');

test('a quarantined document cannot enter parsing', function (): void {
    $version = DocumentVersion::factory()->create([
        'status' => DocumentStatus::Quarantined,
    ]);

    app(ParseDocumentVersion::class)->handle($version->id);

    expect($version->fresh())
        ->status->toBe(DocumentStatus::Quarantined);
});

test(
    'unsupported media is recorded as a permanent failure without throwing',
    function (): void {
        $version = DocumentVersion::factory()->create([
            'original_filename' => 'architecture.pdf',
            'media_type' => 'application/pdf',
            'status' => DocumentStatus::ScanApproved,
        ]);

        app(ParseDocumentVersion::class)->handle($version->id);

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ParseFailed)
            ->failure_code->toBe('unsupported_media_type')
            ->failure_message->toContain('application/pdf')
            ->failure_message->toContain('text/markdown')
            ->failure_message->toContain('text/plain')
            ->parser_name->toBeNull()
            ->parser_version->toBeNull();
    },
);

test(
    'an exhausted transient parser failure records terminal failure',
    function (): void {
        $version = DocumentVersion::factory()
            ->processing()
            ->create([
                'media_type' => 'text/plain',
            ]);

        app(ParseDocumentVersion::class)
            ->markFailed($version->id);

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ParseFailed)
            ->failure_code->toBe('parse_failed');
    },
);
