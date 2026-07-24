<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;

test('authorized editors can approve or reject analyzed document versions', function (): void {
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

    $approvedAfterReview = $approved->fresh();
    $rejectedAfterReview = $rejected->fresh();

    expect($approvedAfterReview->status)
        ->toBe(DocumentStatus::Approved)
        ->and($approvedAfterReview->analyzer_name)
        ->toBe($approved->analyzer_name)
        ->and($approvedAfterReview->analyzer_version)
        ->toBe($approved->analyzer_version)
        ->and($approvedAfterReview->analysis_seed)
        ->toBe($approved->analysis_seed)
        ->and($approvedAfterReview->analysis_flags)
        ->toBe($approved->analysis_flags)
        ->and($rejectedAfterReview->status)
        ->toBe(DocumentStatus::Rejected);
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
        ->status->toBe(DocumentStatus::NeedsReview)
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

test(
    'review rejects missing running failed and legacy analysis states',
    function (DocumentStatus $status): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        OrganizationMembership::factory()
            ->for($project->organization)
            ->for($user)
            ->create();

        $document = Document::factory()
            ->for($project)
            ->create();

        $version = DocumentVersion::factory()
            ->classified()
            ->for($document)
            ->create([
                'status' => $status,
            ]);

        $this
            ->actingAs($user)
            ->post(
                reviewRoute(
                    'approve',
                    $project,
                    $document,
                    $version,
                ),
            )
            ->assertStatus(422);

        expect($version->fresh()->status)->toBe($status);
    },
)->with([
    'legacy parsed' => DocumentStatus::Parsed,
    'analysis pending' => DocumentStatus::AnalysisPending,
    'analysis running' => DocumentStatus::Analyzing,
    'analysis failed' => DocumentStatus::AnalysisFailed,
]);

function reviewRoute(string $action, Project $project, Document $document, DocumentVersion $version): string
{
    return route("organizations.projects.documents.versions.{$action}", [
        'organization' => $project->organization,
        'project' => $project,
        'document' => $document,
        'version' => $version,
    ]);
}
