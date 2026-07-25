<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentClassification;
use App\Domain\Documents\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a document version uses domain defaults and exposes ordered versions', function (): void {
    $document = Document::factory()->create();
    $firstVersion = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'version' => 1,
    ]);
    $secondVersion = DocumentVersion::factory()->classified()->create([
        'document_id' => $document->id,
        'version' => 2,
    ]);

    $document->refresh();

    expect($firstVersion->status)
        ->toBe(DocumentStatus::Uploaded)
        ->and($firstVersion->classification)
        ->toBe(DocumentClassification::Unclassified)
        ->and($document->versions->modelKeys())
        ->toBe([$firstVersion->id, $secondVersion->id])
        ->and($document->latestVersion?->id)
        ->toBe($secondVersion->id)
        ->and($secondVersion->classification)
        ->toBe(DocumentClassification::Specification);
});

test('document queries remain explicitly scoped to an organization', function (): void {
    $visibleOrganization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();

    $visibleDocument = Document::factory()
        ->for(Project::factory()->for($visibleOrganization))
        ->create();
    Document::factory()
        ->for(Project::factory()->for($foreignOrganization))
        ->create();

    expect(Document::query()
        ->forOrganization($visibleOrganization->id)
        ->pluck('id')
        ->all())
        ->toBe([$visibleDocument->id]);
});

test('document versions preserve file identity while allowing pipeline metadata changes', function (): void {
    $documentVersion = DocumentVersion::factory()->create();

    $documentVersion->status = DocumentStatus::Parsing;
    $documentVersion->parser_name = 'mvp-parser';
    $documentVersion->parser_version = '1.0.0';
    $documentVersion->save();

    expect($documentVersion->fresh())
        ->status->toBe(DocumentStatus::Parsing)
        ->parser_name->toBe('mvp-parser');

    $documentVersion->checksum_sha256 = hash('sha256', 'changed');

    expect(fn (): bool => $documentVersion->save())
        ->toThrow(LogicException::class, 'Document version file identity is immutable.');
});

test('document version database constraints reject invalid records', function (): void {
    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    expect(fn (): bool => DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'version' => $version->version,
    ]))
        ->toThrow(QueryException::class);

    expect(fn (): bool => DB::table('document_versions')->insert([
        ...DocumentVersion::factory()->make([
            'document_id' => $document->id,
            'version' => 2,
        ])->getAttributes(),
        'checksum_sha256' => 'invalid',
    ]))
        ->toThrow(QueryException::class);

    expect(fn (): bool => DB::table('document_versions')->insert([
        ...DocumentVersion::factory()->make([
            'document_id' => $document->id,
            'version' => 2,
        ])->getAttributes(),
        'status' => 'unknown',
    ]))
        ->toThrow(QueryException::class);
});

test('document versions retain explicit supersession links and reject self-reference', function (): void {
    $document = Document::factory()->create();
    $firstVersion = DocumentVersion::factory()->approved()->create([
        'document_id' => $document->id,
        'version' => 1,
    ]);
    $secondVersion = DocumentVersion::factory()
        ->superseding($firstVersion)
        ->create();

    expect($secondVersion->supersedes?->id)
        ->toBe($firstVersion->id)
        ->and($firstVersion->supersededBy->modelKeys())
        ->toBe([$secondVersion->id]);

    expect(fn (): bool => DB::table('document_versions')
        ->whereKey($secondVersion->id)
        ->update(['supersedes_document_version_id' => $secondVersion->id]))
        ->toThrow(QueryException::class);
});
