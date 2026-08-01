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

    Cache::store(
        (string) config('cache.limiter'),
    )->flush();
});

/**
 * Create an organization owner and project for upload tests.
 *
 * @return array{
 *     organization: Organization,
 *     project: Project,
 *     user: User
 * }
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
                route(
                    'organizations.projects.documents.store',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                [
                    'title' => 'Architecture baseline',
                    'document' => $upload,
                ],
            )
            ->assertRedirect(
                route(
                    'organizations.projects.documents.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
            )
            ->assertSessionHas(
                'status',
                'document-uploaded',
            );

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
                hash_file(
                    'sha256',
                    $upload->getRealPath(),
                ),
            )
            ->status->toBe(
                DocumentStatus::Quarantined,
            )
            ->classification->toBe(
                DocumentClassification::Unclassified,
            );

        Storage::disk('documents')->assertExists(
            $version->storage_path,
        );

        Queue::assertPushed(
            ScanDocumentVersionJob::class,
            fn (
                ScanDocumentVersionJob $job,
            ): bool => $job->documentVersionId === $version->id,
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

        $documentsIndexRoute = route(
            'organizations.projects.documents.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        );

        $pngContents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z2S8AAAAASUVORK5CYII=',
            true,
        );

        if (! is_string($pngContents)) {
            throw new RuntimeException(
                'The deterministic PNG fixture could not be decoded.',
            );
        }

        $temporaryPath = tempnam(
            sys_get_temp_dir(),
            'aios-document-upload-',
        );

        if (! is_string($temporaryPath)) {
            throw new RuntimeException(
                'The temporary upload fixture could not be created.',
            );
        }

        $bytesWritten = file_put_contents(
            $temporaryPath,
            $pngContents,
        );

        if ($bytesWritten !== strlen($pngContents)) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw new RuntimeException(
                'The PNG upload fixture could not be written.',
            );
        }

        /*
         * Use a real UploadedFile in Symfony test mode. Unlike Laravel's
         * Testing\File fake, getMimeType() now inspects the physical bytes.
         *
         * The client claims text/plain and supplies an allowed .txt filename,
         * while server-side inspection must identify image/png.
         */
        $spoofedImage = new UploadedFile(
            $temporaryPath,
            'architecture.txt',
            'text/plain',
            UPLOAD_ERR_OK,
            true,
        );

        try {
            expect($spoofedImage->getClientOriginalName())
                ->toBe('architecture.txt')
                ->and($spoofedImage->getClientMimeType())
                ->toBe('text/plain')
                ->and($spoofedImage->getMimeType())
                ->toBe('image/png');

            $response = $this
                ->actingAs($user)
                ->from($documentsIndexRoute)
                ->post(
                    route(
                        'organizations.projects.documents.store',
                        [
                            'organization' => $organization,
                            'project' => $project,
                        ],
                    ),
                    [
                        'title' => 'Unsupported image',
                        'document' => $spoofedImage,
                    ],
                );

            $response
                ->assertRedirect($documentsIndexRoute)
                ->assertSessionHasErrors([
                    'document' => 'The document format is not supported. Supported media types: text/markdown, text/plain.',
                ]);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        expect(Document::query()->count())
            ->toBe(0);

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
                route(
                    'organizations.projects.documents.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
            )
            ->post(
                route(
                    'organizations.projects.documents.store',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                [
                    'title' => 'Empty document',
                    'document' => UploadedFile::fake()
                        ->createWithContent(
                            'empty.txt',
                            '',
                        ),
                ],
            )
            ->assertRedirect()
            ->assertSessionHasErrors([
                'document' => 'The document must not be empty.',
            ]);

        expect(Document::query()->count())
            ->toBe(0);

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
         * This fixture uses the reported fake size because this case verifies
         * Laravel's HTTP max-file validation boundary.
         */
        $oversizedUpload = UploadedFile::fake()->create(
            'large.txt',
            20_481,
            'text/plain',
        );

        $this
            ->actingAs($user)
            ->from(
                route(
                    'organizations.projects.documents.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
            )
            ->post(
                route(
                    'organizations.projects.documents.store',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                [
                    'title' => 'Oversized document',
                    'document' => $oversizedUpload,
                ],
            )
            ->assertRedirect()
            ->assertSessionHasErrors('document');

        expect(Document::query()->count())
            ->toBe(0);

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
                route(
                    'organizations.projects.documents.store',
                    [
                        'organization' => $organization,
                        'project' => $foreignProject,
                    ],
                ),
                [
                    'title' => 'Foreign document',
                    'document' => UploadedFile::fake()
                        ->createWithContent(
                            'architecture.txt',
                            'Foreign project content.',
                        ),
                ],
            )
            ->assertNotFound();

        expect(Document::query()->count())
            ->toBe(0);
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

        $documentsIndexRoute = route(
            'organizations.projects.documents.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        );

        $firstResponse = $this
            ->actingAs($user)
            ->from($documentsIndexRoute)
            ->post(
                route(
                    'organizations.projects.documents.store',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                [
                    'title' => 'First',
                    'document' => UploadedFile::fake()
                        ->createWithContent(
                            'first.txt',
                            'First document content.',
                        ),
                ],
            );

        $blockedResponse = $this
            ->actingAs($user)
            ->from($documentsIndexRoute)
            ->post(
                route(
                    'organizations.projects.documents.store',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                [
                    'title' => 'Blocked',
                    'document' => UploadedFile::fake()
                        ->createWithContent(
                            'blocked.txt',
                            'Blocked document content.',
                        ),
                ],
            );

        $firstResponse->assertRedirect();

        $blockedResponse
            ->assertRedirect($documentsIndexRoute)
            ->assertHeader('Retry-After')
            ->assertSessionHasErrors('rate_limit');

        expect(Document::query()->count())
            ->toBe(1);
    },
);
