<?php

declare(strict_types=1);

use App\Application\Documents\Contracts\DocumentParser;
use App\Application\Documents\Exceptions\DocumentProcessingException;
use App\Application\Documents\ParseDocumentVersion;
use App\Domain\Documents\DocumentProcessingFailureCode;
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
            'byte_size' => strlen($content),
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
            ->parser_version->toBe('1.1.0')
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
            ->failure_code->toBe(
                DocumentProcessingFailureCode::UnsupportedMediaType->value,
            )
            ->failure_message->toContain('application/pdf')
            ->failure_message->toContain('text/markdown')
            ->failure_message->toContain('text/plain')
            ->parser_name->toBeNull()
            ->parser_version->toBeNull();

        Queue::assertNothingPushed();
    },
);

test(
    'a stored archive is rejected as a permanent parser failure',
    function (): void {
        $content = "\x50\x4B\x03\x04".str_repeat('A', 128);

        $version = DocumentVersion::factory()->create([
            'original_filename' => 'legacy.txt',
            'media_type' => 'text/plain',
            'byte_size' => strlen($content),
            'storage_disk' => 'documents',
            'storage_path' => 'documents/legacy.txt',
            'status' => DocumentStatus::ScanApproved,
        ]);

        Storage::disk('documents')->put(
            $version->storage_path,
            $content,
        );

        app(ParseDocumentVersion::class)->handle($version->id);

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ParseFailed)
            ->failure_code->toBe(
                DocumentProcessingFailureCode::ArchiveContentDetected->value,
            )
            ->failure_message->toContain(
                'Archive containers are not accepted',
            );

        Queue::assertNothingPushed();
    },
);

test(
    'binary control bytes are rejected as a permanent parser failure',
    function (): void {
        $content = "Valid prefix\x00binary suffix";

        $version = DocumentVersion::factory()->create([
            'original_filename' => 'binary.txt',
            'media_type' => 'text/plain',
            'byte_size' => strlen($content),
            'storage_disk' => 'documents',
            'storage_path' => 'documents/binary.txt',
            'status' => DocumentStatus::ScanApproved,
        ]);

        Storage::disk('documents')->put(
            $version->storage_path,
            $content,
        );

        app(ParseDocumentVersion::class)->handle($version->id);

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ParseFailed)
            ->failure_code->toBe(
                DocumentProcessingFailureCode::BinaryContentDetected->value,
            );

        Queue::assertNothingPushed();
    },
);

test(
    'invalid utf eight is rejected as a permanent parser failure',
    function (): void {
        $content = "\xC3\x28";

        $version = DocumentVersion::factory()->create([
            'original_filename' => 'invalid-encoding.txt',
            'media_type' => 'text/plain',
            'byte_size' => strlen($content),
            'storage_disk' => 'documents',
            'storage_path' => 'documents/invalid-encoding.txt',
            'status' => DocumentStatus::ScanApproved,
        ]);

        Storage::disk('documents')->put(
            $version->storage_path,
            $content,
        );

        app(ParseDocumentVersion::class)->handle($version->id);

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ParseFailed)
            ->failure_code->toBe(
                DocumentProcessingFailureCode::InvalidTextEncoding->value,
            );

        Queue::assertNothingPushed();
    },
);

test(
    'a legacy oversized object is rejected before storage reading',
    function (): void {
        $version = DocumentVersion::factory()->create([
            'original_filename' => 'oversized.txt',
            'media_type' => 'text/plain',
            'byte_size' => (20 * 1024 * 1024) + 1,
            'storage_disk' => 'documents',
            'storage_path' => 'documents/oversized.txt',
            'status' => DocumentStatus::ScanApproved,
        ]);

        app(ParseDocumentVersion::class)->handle($version->id);

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ParseFailed)
            ->failure_code->toBe(
                DocumentProcessingFailureCode::DocumentTooLarge->value,
            );

        Queue::assertNothingPushed();
    },
);

test(
    'a storage read failure remains retryable',
    function (): void {
        $version = DocumentVersion::factory()->create([
            'original_filename' => 'missing.txt',
            'media_type' => 'text/plain',
            'byte_size' => 128,
            'storage_disk' => 'documents',
            'storage_path' => 'documents/missing.txt',
            'status' => DocumentStatus::ScanApproved,
        ]);

        expect(
            fn (): null => app(ParseDocumentVersion::class)
                ->handle($version->id),
        )->toThrow(DocumentProcessingException::class);

        expect($version->fresh())
            ->status->toBe(DocumentStatus::Parsing)
            ->parser_name->toBe('plain-text-mvp')
            ->parser_version->toBe('1.1.0');

        Queue::assertNothingPushed();
    },
);

test(
    'an exhausted transient parser failure preserves its failure code',
    function (): void {
        $version = DocumentVersion::factory()
            ->processing()
            ->create([
                'media_type' => 'text/plain',
            ]);

        $exception = DocumentProcessingException::retryable(
            failureCode: DocumentProcessingFailureCode::StorageReadFailed,
            message: 'The stored document could not be read.',
        );

        app(ParseDocumentVersion::class)->markFailed(
            id: $version->id,
            exception: $exception,
        );

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ParseFailed)
            ->failure_code->toBe(
                DocumentProcessingFailureCode::StorageReadFailed->value,
            )
            ->failure_message->toBe(
                'The stored document could not be read.',
            );
    },
);
