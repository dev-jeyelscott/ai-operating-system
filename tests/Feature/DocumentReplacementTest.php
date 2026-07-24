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
 * Build an explicit version-review route.
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

        $upload = UploadedFile::fake()
            ->createWithContent(
                'architecture-v2.md',
                "# Replacement architecture\n\nVersion two.",
            );

        $expectedChecksum = hash_file(
            'sha256',
            $upload->getRealPath(),
        );

        $this
            ->actingAs($user)
            ->post(
                replacementUploadRoute(
                    $organization,
                    $project,
                    $document,
                    $approved,
                ),
                ['document' => $upload],
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

        expect($approved->fresh())
            ->status->toBe(DocumentStatus::Approved)
            ->analysis_flags->toBe([
                'prompt_injection_detected',
            ])
            ->and($replacement)
            ->version->toBe(2)
            ->status->toBe(DocumentStatus::Quarantined)
            ->storage_path->not->toBe(
                $approved->storage_path,
            )
            ->checksum_sha256->toBe(
                $expectedChecksum,
            )
            ->checksum_sha256->not->toBe(
                $approved->checksum_sha256,
            )
            ->parser_name->toBeNull()
            ->analyzer_name->toBeNull()
            ->analysis_flags->toBeNull();

        Storage::disk('documents')
            ->assertExists($replacement->storage_path);

        Queue::assertPushed(
            ScanDocumentVersionJob::class,
            fn (ScanDocumentVersionJob $job): bool =>
                $job->documentVersionId
                === $replacement->id,
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
            ->assertStatus(422);

        expect(
            DocumentVersion::query()
                ->where(
                    'supersedes_document_version_id',
                    $approved->id,
                )
                ->count(),
        )->toBe(0);

        Storage::disk('documents')
            ->assertDirectoryEmpty('/');

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
                'storage_path' =>
                    'documents/replacement-v2.txt',
                'checksum_sha256' => hash(
                    'sha256',
                    'replacement-version-two',
                ),
                'supersedes_document_version_id' =>
                    $approved->id,
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

        expect($approved->fresh())
            ->status->toBe(DocumentStatus::Superseded)
            ->analysis_flags->toBe([
                'prompt_injection_detected',
            ])
            ->and($replacement->fresh())
            ->status->toBe(DocumentStatus::Approved)
            ->analysis_flags->toBe([
                'replacement_safety_warning',
            ])
            ->and(
                $document
                    ->versions()
                    ->where(
                        'status',
                        DocumentStatus::Approved->value,
                    )
                    ->count(),
            )
            ->toBe(1);
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
                'supersedes_document_version_id' =>
                    $approved->id,
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

        expect($approved->fresh()->status)
            ->toBe(DocumentStatus::Approved)
            ->and($replacement->fresh()->status)
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
                'supersedes_document_version_id' =>
                    $approved->id,
            ]);

        $second = DocumentVersion::factory()
            ->classified()
            ->for($document)
            ->create([
                'version' => 3,
                'supersedes_document_version_id' =>
                    $approved->id,
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
            ->assertStatus(422);

        expect($approved->fresh()->status)
            ->toBe(DocumentStatus::Superseded)
            ->and($first->fresh()->status)
            ->toBe(DocumentStatus::Approved)
            ->and($second->fresh()->status)
            ->toBe(DocumentStatus::NeedsReview)
            ->and(
                $document
                    ->versions()
                    ->where(
                        'status',
                        DocumentStatus::Approved->value,
                    )
                    ->count(),
            )
            ->toBe(1);
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

        expect($approved->fresh()->analysis_flags)
            ->toBe([
                'prompt_injection_detected',
            ]);
    },
);
