<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentStatus;
use App\Jobs\ScanDocumentVersionJob;
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
use LogicException;

beforeEach(function (): void {
    config()->set('filesystems.artifact', 'documents');

    Storage::fake('documents');
    Queue::fake();

    Cache::store(
        (string) config('cache.limiter'),
    )->flush();
});

/**
 * Create an owner, project, document, and approved source revision.
 *
 * @return array{
 *     organization: Organization,
 *     project: Project,
 *     document: Document,
 *     approved: DocumentVersion,
 *     user: User
 * }
 */
function replacementContext(): array
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

    $document = Document::factory()
        ->for($project)
        ->create();

    $approved = DocumentVersion::factory()
        ->approved()
        ->for($document)
        ->create([
            'version' => 1,
            'storage_path' => 'documents/source-v1.txt',
            'checksum_sha256' => hash(
                'sha256',
                'approved-version-one',
            ),
            'analysis_flags' => [
                'prompt_injection_detected',
            ],
        ]);

    return compact(
        'organization',
        'project',
        'document',
        'approved',
        'user',
    );
}

/**
 * Build the replacement-upload route.
 */
function replacementUploadRoute(
    Organization $organization,
    Project $project,
    Document $document,
    DocumentVersion $version,
): string {
    return route(
        'organizations.projects.documents.versions.replacement.store',
        compact(
            'organization',
            'project',
            'document',
            'version',
        ),
    );
}

/**
 * Build an explicit document-version review route.
 */
function replacementReviewRoute(
    string $action,
    Organization $organization,
    Project $project,
    Document $document,
    DocumentVersion $version,
): string {
    return route(
        "organizations.projects.documents.versions.{$action}",
        compact(
            'organization',
            'project',
            'document',
            'version',
        ),
    );
}

test(
    'replacement upload creates a real quarantined revision while preserving current authority',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'document' => $document,
            'approved' => $approved,
            'user' => $user,
        ] = replacementContext();

        $upload = UploadedFile::fake()->createWithContent(
            'architecture-v2.md',
            "# Replacement architecture\n\nVersion two.",
        );

        $realPath = $upload->getRealPath();

        expect($realPath)->toBeString();

        $expectedChecksum = hash_file(
            'sha256',
            (string) $realPath,
        );

        expect($expectedChecksum)->toBeString();

        $this
            ->actingAs($user)
            ->post(
                replacementUploadRoute(
                    $organization,
                    $project,
                    $document,
                    $approved,
                ),
                [
                    'document' => $upload,
                ],
            )
            ->assertRedirect()
            ->assertSessionHas(
                'status',
                'document-replacement-uploaded',
            );

        $replacement = DocumentVersion::query()
            ->where(
                'supersedes_document_version_id',
                $approved->id,
            )
            ->sole();

        $approved->refresh();
        $replacement->refresh();

        expect($approved->status)
            ->toBe(DocumentStatus::Approved)
            ->and($approved->analysis_flags)
            ->toBe([
                'prompt_injection_detected',
            ])
            ->and($replacement->version)
            ->toBe(2)
            ->and($replacement->status)
            ->toBe(DocumentStatus::Quarantined)
            ->and($replacement->storage_path)
            ->not->toBe($approved->storage_path)
            ->and($replacement->checksum_sha256)
            ->toBe($expectedChecksum)
            ->and($replacement->checksum_sha256)
            ->not->toBe($approved->checksum_sha256)
            ->and($replacement->parser_name)
            ->toBeNull()
            ->and($replacement->analyzer_name)
            ->toBeNull()
            ->and($replacement->analysis_flags)
            ->toBeNull();

        Storage::disk('documents')
            ->assertExists($replacement->storage_path);

        Queue::assertPushed(
            ScanDocumentVersionJob::class,
            fn (ScanDocumentVersionJob $job): bool => (
                $job->documentVersionId === $replacement->id
            ),
        );
    },
);

test(
    'failed replacement validation cleans up the newly stored object',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'document' => $document,
            'approved' => $approved,
            'user' => $user,
        ] = replacementContext();

        $approved->forceFill([
            'status' => DocumentStatus::Rejected,
        ])->save();

        $this
            ->actingAs($user)
            ->post(
                replacementUploadRoute(
                    $organization,
                    $project,
                    $document,
                    $approved,
                ),
                [
                    'document' => UploadedFile::fake()
                        ->createWithContent(
                            'invalid-replacement.txt',
                            'Should be cleaned up.',
                        ),
                ],
            )
            ->assertRedirect(
                route('organizations.projects.documents.show', [
                    'organization' => $organization,
                    'project' => $project,
                    'document' => $document,
                ]),
            )
            ->assertSessionHasErrors([
                'action' => 'Only the current approved document version can be replaced.',
            ]);

        $replacementCount = DocumentVersion::query()
            ->where(
                'supersedes_document_version_id',
                $approved->id,
            )
            ->count();

        expect($replacementCount)->toBe(0);

        expect(
            Storage::disk('documents')->allFiles(),
        )->toBeEmpty();

        Queue::assertNothingPushed();
    },
);

test(
    'approving a replacement atomically transfers authority',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'document' => $document,
            'approved' => $approved,
            'user' => $user,
        ] = replacementContext();

        $replacement = DocumentVersion::factory()
            ->classified()
            ->for($document)
            ->create([
                'version' => 2,
                'storage_path' => 'documents/replacement-v2.txt',
                'checksum_sha256' => hash(
                    'sha256',
                    'replacement-version-two',
                ),
                'supersedes_document_version_id' => $approved->id,
                'analysis_flags' => [
                    'replacement_safety_warning',
                ],
            ]);

        $this
            ->actingAs($user)
            ->post(
                replacementReviewRoute(
                    'approve',
                    $organization,
                    $project,
                    $document,
                    $replacement,
                ),
            )
            ->assertRedirect();

        $approved->refresh();
        $replacement->refresh();

        expect($approved->status)
            ->toBe(DocumentStatus::Superseded)
            ->and($approved->analysis_flags)
            ->toBe([
                'prompt_injection_detected',
            ])
            ->and($replacement->status)
            ->toBe(DocumentStatus::Approved)
            ->and($replacement->analysis_flags)
            ->toBe([
                'replacement_safety_warning',
            ]);

        $approvedVersionCount = $document
            ->versions()
            ->where(
                'status',
                DocumentStatus::Approved->value,
            )
            ->count();

        expect($approvedVersionCount)->toBe(1);
    },
);

test(
    'rejecting a replacement leaves the previous approved version authoritative',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'document' => $document,
            'approved' => $approved,
            'user' => $user,
        ] = replacementContext();

        $replacement = DocumentVersion::factory()
            ->classified()
            ->for($document)
            ->create([
                'version' => 2,
                'supersedes_document_version_id' => $approved->id,
            ]);

        $this
            ->actingAs($user)
            ->post(
                replacementReviewRoute(
                    'reject',
                    $organization,
                    $project,
                    $document,
                    $replacement,
                ),
            )
            ->assertRedirect();

        $approved->refresh();
        $replacement->refresh();

        expect($approved->status)
            ->toBe(DocumentStatus::Approved)
            ->and($replacement->status)
            ->toBe(DocumentStatus::Rejected);
    },
);

test(
    'only one competing replacement can become authoritative',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'document' => $document,
            'approved' => $approved,
            'user' => $user,
        ] = replacementContext();

        $first = DocumentVersion::factory()
            ->classified()
            ->for($document)
            ->create([
                'version' => 2,
                'supersedes_document_version_id' => $approved->id,
            ]);

        $second = DocumentVersion::factory()
            ->classified()
            ->for($document)
            ->create([
                'version' => 3,
                'supersedes_document_version_id' => $approved->id,
            ]);

        $this
            ->actingAs($user)
            ->post(
                replacementReviewRoute(
                    'approve',
                    $organization,
                    $project,
                    $document,
                    $first,
                ),
            )
            ->assertRedirect();

        $this
            ->actingAs($user)
            ->post(
                replacementReviewRoute(
                    'approve',
                    $organization,
                    $project,
                    $document,
                    $second,
                ),
            )
            ->assertRedirect(
                route('organizations.projects.documents.show', [
                    'organization' => $organization,
                    'project' => $project,
                    'document' => $document,
                ]),
            )
            ->assertSessionHasErrors([
                'action' => 'The replacement predecessor is no longer the approved version.',
            ]);

        $approved->refresh();
        $first->refresh();
        $second->refresh();

        expect($approved->status)
            ->toBe(DocumentStatus::Superseded)
            ->and($first->status)
            ->toBe(DocumentStatus::Approved)
            ->and($second->status)
            ->toBe(DocumentStatus::NeedsReview);

        $approvedVersionCount = $document
            ->versions()
            ->where(
                'status',
                DocumentStatus::Approved->value,
            )
            ->count();

        expect($approvedVersionCount)->toBe(1);
    },
);

test(
    'completed historical analysis metadata cannot be changed',
    function (): void {
        [
            'approved' => $approved,
        ] = replacementContext();

        expect(
            fn () => $approved
                ->forceFill([
                    'analysis_flags' => [],
                ])
                ->save(),
        )->toThrow(
            LogicException::class,
            'Completed document analysis metadata is immutable.',
        );

        /*
         * The failed update leaves the in-memory model dirty. Refresh it before
         * asserting the persisted historical evidence.
         */
        $approved->refresh();

        expect($approved->analysis_flags)
            ->toBe([
                'prompt_injection_detected',
            ]);
    },
);
