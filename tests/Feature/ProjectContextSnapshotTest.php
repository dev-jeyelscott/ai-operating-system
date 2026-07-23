<?php

declare(strict_types=1);

use App\Application\Projects\CreateProject;
use App\Application\Projects\CreateProjectContextSnapshot;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Projects\ProjectType;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;

test('a context snapshot pins the current configuration version and approved document checksums', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $project = app(CreateProject::class)->handle($user->id, $organization->id, 'Snapshot project', null, ProjectType::WebApplication);
    $approvedDocument = Document::factory()->for($project)->create();
    $approvedVersion = DocumentVersion::factory()->for($approvedDocument)->approved()->create();
    $unapprovedDocument = Document::factory()->for($project)->create();
    DocumentVersion::factory()->for($unapprovedDocument)->create(['status' => DocumentStatus::Parsed]);

    $snapshot = app(CreateProjectContextSnapshot::class)->handle($organization->id, $project->id);

    expect($snapshot->configuration_revision)->toBe(1);
    $this->assertJsonStringEqualsJsonString(
        json_encode([[
            'document_id' => $approvedDocument->id,
            'document_version_id' => $approvedVersion->id,
            'version' => 1,
            'checksum_sha256' => $approvedVersion->checksum_sha256,
        ]], JSON_THROW_ON_ERROR),
        json_encode($snapshot->approved_document_versions, JSON_THROW_ON_ERROR),
    );

    $approvedVersion->forceFill(['status' => DocumentStatus::Superseded])->save();

    $this->assertJsonStringEqualsJsonString(
        json_encode($snapshot->approved_document_versions, JSON_THROW_ON_ERROR),
        json_encode(
            $snapshot->fresh()->approved_document_versions,
            JSON_THROW_ON_ERROR,
        ),
    );

    expect(fn (): bool => $snapshot->forceFill(['configuration_revision' => 2])->save())
        ->toThrow(LogicException::class, 'Project context snapshots are immutable.');
});
