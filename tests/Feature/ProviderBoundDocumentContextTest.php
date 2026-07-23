<?php

declare(strict_types=1);

use App\Application\Documents\BuildProviderBoundDocumentContext;
use App\Application\Projects\CreateProject;
use App\Application\Projects\CreateProjectContextSnapshot;
use App\Domain\Projects\ProjectType;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;

test('provider-bound context redacts secrets while preserving the stored source', function (): void {
    $organization = Organization::factory()->create();
    $project = app(CreateProject::class)->handle(
        User::factory()->create()->id,
        $organization->id,
        'Redaction project',
        null,
        ProjectType::WebApplication,
    );
    $document = Document::factory()->for($project)->create();
    $version = DocumentVersion::factory()->for($document)->approved()->create([
        'parsed_content' => 'token=super-secret ghp_abcdefghijklmnopqrstuvwxyz1234567890',
    ]);
    $snapshot = app(CreateProjectContextSnapshot::class)->handle($organization->id, $project->id);

    $context = app(BuildProviderBoundDocumentContext::class)->handle($snapshot);

    expect($context[0]['content'])->toBe('[REDACTED] [REDACTED]')
        ->and($version->fresh()->parsed_content)->toContain('super-secret')
        ->and($context[0]['checksum_sha256'])->toBe($version->checksum_sha256);
});
