<?php

declare(strict_types=1);

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\Exceptions\DocumentProcessingException;
use App\Application\Documents\StoreProjectDocument;
use App\Domain\Documents\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
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
    Queue::fake();

    Cache::store((string) config('cache.limiter'))->flush();
});

/**
 * Create an organization owner and project for hardening tests.
 *
 * @return array{
 *     organization: Organization,
 *     project: Project,
 *     user: User
 * }
 */
function documentHardeningOwner(): array
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
    'a text payload using an executable extension is rejected before storage',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'user' => $user,
        ] = documentHardeningOwner();

        $response = $this
            ->actingAs($user)
            ->from(route(
                'organizations.projects.documents.index',
                compact('organization', 'project'),
            ))
            ->post(
                route(
                    'organizations.projects.documents.store',
                    compact('organization', 'project'),
                ),
                [
                    'title' => 'Spoofed executable',
                    'document' => UploadedFile::fake()->createWithContent(
                        'requirements.php',
                        'This content pretends to be plain text.',
                    ),
                ],
            );

        $response
            ->assertRedirect()
            ->assertSessionHasErrors('document');

        expect(Document::query()->count())->toBe(0);

        Storage::disk('documents')->assertDirectoryEmpty('/');
        Queue::assertNothingPushed();
    },
);

test(
    'a renamed binary payload is rejected before durable persistence',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'user' => $user,
        ] = documentHardeningOwner();

        $response = $this
            ->actingAs($user)
            ->from(route(
                'organizations.projects.documents.index',
                compact('organization', 'project'),
            ))
            ->post(
                route(
                    'organizations.projects.documents.store',
                    compact('organization', 'project'),
                ),
                [
                    'title' => 'Spoofed image',
                    'document' => UploadedFile::fake()->createWithContent(
                        'requirements.txt',
                        "GIF89a\x00\x00\x00\x00",
                    ),
                ],
            );

        $response
            ->assertRedirect()
            ->assertSessionHasErrors('document');

        expect(Document::query()->count())->toBe(0);

        Storage::disk('documents')->assertDirectoryEmpty('/');
        Queue::assertNothingPushed();
    },
);

test(
    'an archive renamed as text is rejected before durable persistence',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'user' => $user,
        ] = documentHardeningOwner();

        $response = $this
            ->actingAs($user)
            ->from(route(
                'organizations.projects.documents.index',
                compact('organization', 'project'),
            ))
            ->post(
                route(
                    'organizations.projects.documents.store',
                    compact('organization', 'project'),
                ),
                [
                    'title' => 'Renamed archive',
                    'document' => UploadedFile::fake()->createWithContent(
                        'requirements.txt',
                        "\x50\x4B\x03\x04".str_repeat('A', 128),
                    ),
                ],
            );

        $response
            ->assertRedirect()
            ->assertSessionHasErrors('document');

        expect(Document::query()->count())->toBe(0);

        Storage::disk('documents')->assertDirectoryEmpty('/');
        Queue::assertNothingPushed();
    },
);

test(
    'the application service rejects an unsafe upload without an http request',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
        ] = documentHardeningOwner();

        $upload = UploadedFile::fake()->createWithContent(
            'archive.txt',
            "\x50\x4B\x03\x04".str_repeat('A', 128),
        );

        expect(
            fn (): Document => app(StoreProjectDocument::class)->handle(
                organization: $organization,
                project: $project,
                title: 'Direct command archive',
                documentClass: 'requirements',
                uploadedFile: $upload,
                auditContext: AuditContext::system(
                    actorId: 'hardening-test',
                ),
            ),
        )->toThrow(DocumentProcessingException::class);

        expect(Document::query()->count())->toBe(0);

        Storage::disk('documents')->assertDirectoryEmpty('/');
        Queue::assertNothingPushed();
    },
);

test(
    'an unsafe replacement cannot create a new document version',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'user' => $user,
        ] = documentHardeningOwner();

        $document = Document::factory()
            ->for($project)
            ->create();

        $approvedVersion = DocumentVersion::factory()
            ->for($document)
            ->create([
                'version' => 1,
                'status' => DocumentStatus::Approved,
            ]);

        $response = $this
            ->actingAs($user)
            ->from(route(
                'organizations.projects.documents.show',
                compact('organization', 'project', 'document'),
            ))
            ->post(
                route(
                    'organizations.projects.documents.versions.replacement.store',
                    [
                        'organization' => $organization,
                        'project' => $project,
                        'document' => $document,
                        'version' => $approvedVersion,
                    ],
                ),
                [
                    'document' => UploadedFile::fake()->createWithContent(
                        'replacement.txt',
                        "\x50\x4B\x03\x04".str_repeat('B', 128),
                    ),
                ],
            );

        $response
            ->assertRedirect()
            ->assertSessionHasErrors('document');

        expect($document->versions()->count())->toBe(1)
            ->and($approvedVersion->fresh()->status)
            ->toBe(DocumentStatus::Approved);

        Queue::assertNothingPushed();
    },
);
