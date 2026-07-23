<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;

test('authorized editors can approve or reject parsed document versions', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    OrganizationMembership::factory()->for($project->organization)->for($user)->create();
    $document = Document::factory()->for($project)->create();
    $approved = DocumentVersion::factory()->for($document)->classified()->create();
    $rejected = DocumentVersion::factory()->for($document)->classified()->create(['version' => 2]);

    $this->actingAs($user)
        ->post(reviewRoute('approve', $project, $document, $approved))
        ->assertRedirect(route('organizations.projects.documents.show', [
            'organization' => $project->organization,
            'project' => $project,
            'document' => $document,
        ]));

    $this->actingAs($user)
        ->post(reviewRoute('reject', $project, $document, $rejected))
        ->assertRedirect();

    expect($approved->fresh()->status)->toBe(DocumentStatus::Approved)
        ->and($rejected->fresh()->status)->toBe(DocumentStatus::Rejected);
});

test('superseding an approved version preserves it and creates an unapproved successor', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    OrganizationMembership::factory()->owner()->for($project->organization)->for($user)->create();
    $document = Document::factory()->for($project)->create();
    $approved = DocumentVersion::factory()->for($document)->approved()->create([
        'parsed_content' => 'Approved architecture.',
        'analysis_summary' => 'Architecture summary.',
    ]);

    $this->actingAs($user)
        ->post(reviewRoute('supersede', $project, $document, $approved))
        ->assertRedirect();

    $successor = DocumentVersion::query()
        ->where('supersedes_document_version_id', $approved->id)
        ->firstOrFail();

    expect($approved->fresh()->status)->toBe(DocumentStatus::Superseded)
        ->and($successor)
        ->version->toBe(2)
        ->status->toBe(DocumentStatus::Parsed)
        ->checksum_sha256->toBe($approved->checksum_sha256)
        ->parsed_content->toBe('Approved architecture.');
});

test('review commands are unavailable to viewers and cannot cross document boundaries', function (): void {
    $viewer = User::factory()->create();
    $project = Project::factory()->create();
    OrganizationMembership::factory()->viewer()->for($project->organization)->for($viewer)->create();
    $document = Document::factory()->for($project)->create();
    $version = DocumentVersion::factory()->for($document)->classified()->create();

    $this->actingAs($viewer)
        ->post(reviewRoute('approve', $project, $document, $version))
        ->assertForbidden();

    $otherDocument = Document::factory()->for($project)->create();
    $editor = User::factory()->create();
    OrganizationMembership::factory()->for($project->organization)->for($editor)->create();

    $this->actingAs($editor)
        ->post(reviewRoute('approve', $project, $otherDocument, $version))
        ->assertNotFound();
});

function reviewRoute(string $action, Project $project, Document $document, DocumentVersion $version): string
{
    return route("organizations.projects.documents.versions.{$action}", [
        'organization' => $project->organization,
        'project' => $project,
        'document' => $document,
        'version' => $version,
    ]);
}
