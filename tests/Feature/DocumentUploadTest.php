<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentClassification;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\ScanDocumentVersionJob;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config()->set('filesystems.artifact', 'documents');
    Storage::fake('documents');
    Cache::store((string) config('cache.limiter'))->flush();
});

/**
 * @return array{organization: Organization, project: Project, user: User}
 */
function documentUploadOwner(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    OrganizationMembership::factory()
        ->owner()
        ->for($organization)
        ->for($user)
        ->create();

    return compact('organization', 'project', 'user');
}

test('an authorized member stores a private project-scoped document with a computed checksum', function (): void {
    ['organization' => $organization, 'project' => $project, 'user' => $user]
        = documentUploadOwner();
    $upload = UploadedFile::fake()->create('architecture.pdf', 128, 'application/pdf');
    Queue::fake();

    $this
        ->actingAs($user)
        ->post(route('organizations.projects.documents.store', [
            'organization' => $organization,
            'project' => $project,
        ]), [
            'title' => 'Architecture baseline',
            'document' => $upload,
        ])
        ->assertRedirect(route('organizations.projects.show', [
            'organization' => $organization,
            'project' => $project,
        ]))
        ->assertSessionHas('status', 'document-uploaded');

    $document = Document::query()->sole();
    $version = $document->latestVersion;

    expect($document)
        ->project_id->toBe($project->id)
        ->title->toBe('Architecture baseline')
        ->and($version)
        ->not->toBeNull()
        ->storage_disk->toBe('documents')
        ->storage_path->toStartWith(
            "documents/organizations/{$organization->id}/projects/{$project->id}/",
        )
        ->checksum_sha256->toBe(hash_file('sha256', $upload->getRealPath()))
        ->status->toBe(DocumentStatus::Quarantined)
        ->classification->toBe(DocumentClassification::Unclassified);

    Storage::disk('documents')->assertExists($version->storage_path);
    Queue::assertPushed(
        ScanDocumentVersionJob::class,
        fn (ScanDocumentVersionJob $job): bool => $job->documentVersionId === $version->id,
    );
});

test('uploads reject unsupported file types and oversized files before storage', function (): void {
    ['organization' => $organization, 'project' => $project, 'user' => $user]
        = documentUploadOwner();

    foreach ([
        UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        UploadedFile::fake()->create('large.pdf', 20_481, 'application/pdf'),
    ] as $upload) {
        $this
            ->actingAs($user)
            ->from(route('organizations.projects.show', [
                'organization' => $organization,
                'project' => $project,
            ]))
            ->post(route('organizations.projects.documents.store', [
                'organization' => $organization,
                'project' => $project,
            ]), [
                'title' => 'Rejected document',
                'document' => $upload,
            ])
            ->assertRedirect(route('organizations.projects.show', [
                'organization' => $organization,
                'project' => $project,
            ]))
            ->assertSessionHasErrors('document');
    }

    expect(Document::query()->count())->toBe(0);
    Storage::disk('documents')->assertDirectoryEmpty('/');
});

test('uploads cannot address a project outside the route organization', function (): void {
    ['organization' => $organization, 'user' => $user]
        = documentUploadOwner();
    $foreignProject = Project::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('organizations.projects.documents.store', [
            'organization' => $organization,
            'project' => $foreignProject,
        ]), [
            'title' => 'Foreign document',
            'document' => UploadedFile::fake()->create(
                'architecture.pdf',
                128,
                'application/pdf',
            ),
        ])
        ->assertNotFound();

    expect(Document::query()->count())->toBe(0);
});

test('uploads are rate limited per actor and organization before persistence', function (): void {
    config()->set('rate-limits.project_commands.upload.per_minute', 1);
    config()->set('rate-limits.project_commands.upload.per_hour', 20);

    ['organization' => $organization, 'project' => $project, 'user' => $user]
        = documentUploadOwner();

    $firstResponse = $this
        ->actingAs($user)
        ->from(route('organizations.projects.show', [
            'organization' => $organization,
            'project' => $project,
        ]))
        ->post(route('organizations.projects.documents.store', [
            'organization' => $organization,
            'project' => $project,
        ]), [
            'title' => 'First',
            'document' => UploadedFile::fake()->create(
                'first.pdf',
                128,
                'application/pdf',
            ),
        ]);

    $blockedResponse = $this
        ->actingAs($user)
        ->from(route('organizations.projects.show', [
            'organization' => $organization,
            'project' => $project,
        ]))
        ->post(route('organizations.projects.documents.store', [
            'organization' => $organization,
            'project' => $project,
        ]), [
            'title' => 'Blocked',
            'document' => UploadedFile::fake()->create(
                'blocked.pdf',
                128,
                'application/pdf',
            ),
        ]);

    $firstResponse->assertRedirect();
    $blockedResponse
        ->assertRedirect(route('organizations.projects.show', [
            'organization' => $organization,
            'project' => $project,
        ]))
        ->assertHeader('Retry-After')
        ->assertSessionHasErrors('rate_limit');

    expect(Document::query()->count())->toBe(1);
});
