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
 * Create an organization owner and project for upload tests.
 *
 * @return array{organization: Organization, project: Project, user: User}
 */
function documentUploadOwner(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $project = Project::factory()
        ->for($organization)
        ->create();

    OrganizationMembership::factory()
        ->owner()
        ->for($organization)
        ->for($user)
        ->create();

    return compact('organization', 'project', 'user');
}

test(
    'an authorized member stores a private parser-supported document',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'user' => $user,
        ] = documentUploadOwner();

        $upload = UploadedFile::fake()->create(
            'architecture.md',
            128,
            'text/markdown',
        );

        Queue::fake();

        $this
            ->actingAs($user)
            ->post(
                route('organizations.projects.documents.store', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
                [
                    'title' => 'Architecture baseline',
                    'document' => $upload,
                ],
            )
            ->assertRedirect(
                route('organizations.projects.show', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
            )
            ->assertSessionHas('status', 'document-uploaded');

        $document = Document::query()->sole();
        $version = $document->latestVersion;

        expect($document)
            ->project_id->toBe($project->id)
            ->title->toBe('Architecture baseline')
            ->and($version)
            ->not->toBeNull()
            ->media_type->toBeIn([
                'text/markdown',
                'text/plain',
            ])
            ->storage_disk->toBe('documents')
            ->storage_path->toStartWith(
                "documents/organizations/{$organization->id}/projects/{$project->id}/",
            )
            ->checksum_sha256->toBe(
                hash_file('sha256', $upload->getRealPath()),
            )
            ->status->toBe(DocumentStatus::Quarantined)
            ->classification->toBe(
                DocumentClassification::Unclassified,
            );

        Storage::disk('documents')
            ->assertExists($version->storage_path);

        Queue::assertPushed(
            ScanDocumentVersionJob::class,
            fn (ScanDocumentVersionJob $job): bool => $job->documentVersionId === $version->id,
        );
    },
);

test(
    'unsupported media is rejected before storage or queue dispatch',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'user' => $user,
        ] = documentUploadOwner();

        Queue::fake();

        $this
            ->actingAs($user)
            ->from(
                route('organizations.projects.show', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
            )
            ->post(
                route('organizations.projects.documents.store', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
                [
                    'title' => 'Unsupported PDF',
                    'document' => UploadedFile::fake()->create(
                        'architecture.pdf',
                        128,
                        'application/pdf',
                    ),
                ],
            )
            ->assertRedirect(
                route('organizations.projects.show', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
            )
            ->assertSessionHasErrors([
                'document' => 'The document format is not supported. Supported media types: text/markdown, text/plain.',
            ]);

        expect(Document::query()->count())->toBe(0);

        Storage::disk('documents')
            ->assertDirectoryEmpty('/');

        Queue::assertNothingPushed();
    },
);

test(
    'oversized supported documents are rejected before storage',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'user' => $user,
        ] = documentUploadOwner();

        Queue::fake();

        $this
            ->actingAs($user)
            ->from(
                route('organizations.projects.show', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
            )
            ->post(
                route('organizations.projects.documents.store', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
                [
                    'title' => 'Oversized document',
                    'document' => UploadedFile::fake()->create(
                        'large.txt',
                        20_481,
                        'text/plain',
                    ),
                ],
            )
            ->assertRedirect()
            ->assertSessionHasErrors('document');

        expect(Document::query()->count())->toBe(0);

        Storage::disk('documents')
            ->assertDirectoryEmpty('/');

        Queue::assertNothingPushed();
    },
);

test(
    'uploads cannot address a project outside the route organization',
    function (): void {
        [
            'organization' => $organization,
            'user' => $user,
        ] = documentUploadOwner();

        $foreignProject = Project::factory()->create();

        $this
            ->actingAs($user)
            ->post(
                route('organizations.projects.documents.store', [
                    'organization' => $organization,
                    'project' => $foreignProject,
                ]),
                [
                    'title' => 'Foreign document',
                    'document' => UploadedFile::fake()->create(
                        'architecture.txt',
                        128,
                        'text/plain',
                    ),
                ],
            )
            ->assertNotFound();

        expect(Document::query()->count())->toBe(0);
    },
);

test(
    'uploads are rate limited per actor and organization',
    function (): void {
        config()->set(
            'rate-limits.project_commands.upload.per_minute',
            1,
        );
        config()->set(
            'rate-limits.project_commands.upload.per_hour',
            20,
        );

        Queue::fake();

        [
            'organization' => $organization,
            'project' => $project,
            'user' => $user,
        ] = documentUploadOwner();

        $firstResponse = $this
            ->actingAs($user)
            ->from(
                route('organizations.projects.show', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
            )
            ->post(
                route('organizations.projects.documents.store', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
                [
                    'title' => 'First',
                    'document' => UploadedFile::fake()->create(
                        'first.txt',
                        128,
                        'text/plain',
                    ),
                ],
            );

        $blockedResponse = $this
            ->actingAs($user)
            ->from(
                route('organizations.projects.show', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
            )
            ->post(
                route('organizations.projects.documents.store', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
                [
                    'title' => 'Blocked',
                    'document' => UploadedFile::fake()->create(
                        'blocked.txt',
                        128,
                        'text/plain',
                    ),
                ],
            );

        $firstResponse->assertRedirect();

        $blockedResponse
            ->assertRedirect(
                route('organizations.projects.show', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
            )
            ->assertHeader('Retry-After')
            ->assertSessionHasErrors('rate_limit');

        expect(Document::query()->count())->toBe(1);
    },
);
