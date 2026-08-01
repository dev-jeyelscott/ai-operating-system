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

        $contents = <<<'MARKDOWN'
# Architecture

This document defines the initial architecture baseline.
MARKDOWN;

        $upload = UploadedFile::fake()->createWithContent(
            'architecture.md',
            $contents,
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
                route('organizations.projects.documents.index', [
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
            ->byte_size->toBe(strlen($contents))
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

        /*
         * Keep an allowed .txt extension so this test reaches server-side
         * MIME inspection instead of stopping at the extension rule.
         */
        $spoofedPdf = UploadedFile::fake()->createWithContent(
            'architecture.txt',
            "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n",
        );

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
                    'document' => $spoofedPdf,
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
    'empty documents are rejected before storage or queue dispatch',
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
                    'title' => 'Empty document',
                    'document' => UploadedFile::fake()->createWithContent(
                        'empty.txt',
                        '',
                    ),
                ],
            )
            ->assertRedirect()
            ->assertSessionHasErrors([
                'document' => 'The document must not be empty.',
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

        /*
         * This fixture intentionally uses the reported fake size because
         * this test verifies Laravel's HTTP max-file validation boundary.
         */
        $oversizedUpload = UploadedFile::fake()->create(
            'large.txt',
            20_481,
            'text/plain',
        );

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
                    'document' => $oversizedUpload,
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
                    'document' => UploadedFile::fake()->createWithContent(
                        'architecture.txt',
                        'Foreign project content.',
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
                    'document' => UploadedFile::fake()->createWithContent(
                        'first.txt',
                        'First document content.',
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
                    'document' => UploadedFile::fake()->createWithContent(
                        'blocked.txt',
                        'Blocked document content.',
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
