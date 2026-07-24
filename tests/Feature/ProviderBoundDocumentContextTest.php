<?php

declare(strict_types=1);

use App\Application\Documents\BuildProviderBoundDocumentContext;
use App\Application\Documents\Exceptions\DocumentContextIntegrityException;
use App\Application\Projects\CreateProject;
use App\Application\Projects\CreateProjectContextSnapshot;
use App\Domain\Projects\ProjectType;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\ProjectContextSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config()->set(
        'filesystems.artifact',
        'documents',
    );

    Storage::fake('documents');
});

/**
 * Create one approved document and a version 2 context snapshot.
 *
 * @return array{
 *     organization: Organization,
 *     version: DocumentVersion,
 *     snapshot: ProjectContextSnapshot
 * }
 */
function providerBoundContextFixture(): array
{
    $organization = Organization::factory()->create();

    $project = app(CreateProject::class)->handle(
        actorUserId: User::factory()->create()->id,
        organizationId: $organization->id,
        name: 'Provider context project',
        description: null,
        projectType: ProjectType::WebApplication,
    );

    $document = Document::factory()
        ->for($project)
        ->create();

    $version = DocumentVersion::factory()
        ->for($document)
        ->approved()
        ->create([
            'parsed_content' => implode(' ', [
                'token=super-secret',
                'ghp_abcdefghijklmnopqrstuvwxyz1234567890',
            ]),
            'analysis_flags' => [
                'prompt_injection',
            ],
        ]);

    $snapshot = app(
        CreateProjectContextSnapshot::class,
    )->handle(
        organizationId: $organization->id,
        projectId: $project->id,
    );

    return compact(
        'organization',
        'version',
        'snapshot',
    );
}

test(
    'provider context uses verified immutable content and redacts secrets',
    function (): void {
        [
            'version' => $version,
            'snapshot' => $snapshot,
        ] = providerBoundContextFixture();

        $context = app(
            BuildProviderBoundDocumentContext::class,
        )->handle($snapshot);

        expect($context)
            ->toHaveCount(1)
            ->and($context[0]['content'])
            ->toBe('[REDACTED] [REDACTED]')
            ->and(
                $context[0]['checksum_sha256'],
            )
            ->toBe($version->checksum_sha256)
            ->and($context[0]['safety_flags'])
            ->toBe(['prompt_injection'])
            ->and($version->fresh()->parsed_content)
            ->toContain('super-secret');
    },
);

test(
    'provider dispatch fails when parsed database content no longer matches the snapshot',
    function (): void {
        [
            'version' => $version,
            'snapshot' => $snapshot,
        ] = providerBoundContextFixture();

        /*
         * Use the query builder intentionally to simulate privileged database
         * drift that bypasses Eloquent model events.
         */
        DB::table('document_versions')
            ->where('id', $version->id)
            ->update([
                'parsed_content' => 'Drifted parsed content.',
                'updated_at' => now(),
            ]);

        expect(
            fn (): array => app(
                BuildProviderBoundDocumentContext::class,
            )->handle($snapshot),
        )->toThrow(
            DocumentContextIntegrityException::class,
            sprintf(
                'Document version %d failed parsed content checksum verification.',
                $version->id,
            ),
        );
    },
);

test(
    'provider dispatch fails when the immutable artifact is missing',
    function (): void {
        [
            'version' => $version,
            'snapshot' => $snapshot,
        ] = providerBoundContextFixture();

        $entry =
            $snapshot->approved_document_versions[0];

        Storage::disk(
            $entry['parsed_content_storage_disk'],
        )->delete(
            $entry['parsed_content_storage_path'],
        );

        expect(
            fn (): array => app(
                BuildProviderBoundDocumentContext::class,
            )->handle($snapshot),
        )->toThrow(
            DocumentContextIntegrityException::class,
            sprintf(
                'The immutable content artifact for document version %d is missing.',
                $version->id,
            ),
        );
    },
);

test(
    'provider dispatch fails when immutable artifact bytes are changed',
    function (): void {
        [
            'version' => $version,
            'snapshot' => $snapshot,
        ] = providerBoundContextFixture();

        $entry =
            $snapshot->approved_document_versions[0];

        Storage::disk(
            $entry['parsed_content_storage_disk'],
        )->put(
            $entry['parsed_content_storage_path'],
            'Tampered artifact.',
        );

        expect(
            fn (): array => app(
                BuildProviderBoundDocumentContext::class,
            )->handle($snapshot),
        )->toThrow(
            DocumentContextIntegrityException::class,
            sprintf(
                'The immutable content artifact for document version %d failed integrity verification.',
                $version->id,
            ),
        );
    },
);

test(
    'provider dispatch fails when an expected version is missing',
    function (): void {
        [
            'version' => $version,
            'snapshot' => $snapshot,
        ] = providerBoundContextFixture();

        DB::table('document_versions')
            ->where('id', $version->id)
            ->delete();

        expect(
            fn (): array => app(
                BuildProviderBoundDocumentContext::class,
            )->handle($snapshot),
        )->toThrow(
            DocumentContextIntegrityException::class,
            sprintf(
                'Expected document version %d is unavailable.',
                $version->id,
            ),
        );
    },
);
