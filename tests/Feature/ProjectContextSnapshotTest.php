<?php

declare(strict_types=1);

use App\Application\Documents\ApprovedDocumentSetFingerprint;
use App\Application\Projects\CreateProject;
use App\Application\Projects\CreateProjectContextSnapshot;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Projects\ProjectType;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\ProjectContextSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use LogicException;

beforeEach(function (): void {
    config()->set(
        'filesystems.artifact',
        'documents',
    );

    Storage::fake('documents');
});

test(
    'identical configuration and approved documents return the same snapshot',
    function (): void {
        $organization = Organization::factory()->create();

        $project = app(CreateProject::class)->handle(
            actorUserId: User::factory()->create()->id,
            organizationId: $organization->id,
            name: 'Snapshot project',
            description: null,
            projectType: ProjectType::WebApplication,
        );

        $document = Document::factory()
            ->for($project)
            ->create();

        $approvedVersion = DocumentVersion::factory()
            ->for($document)
            ->approved()
            ->create([
                'parsed_content' => 'Immutable architecture content.',
                'analysis_flags' => [
                    'prompt_injection',
                ],
            ]);

        $unapprovedDocument = Document::factory()
            ->for($project)
            ->create();

        DocumentVersion::factory()
            ->for($unapprovedDocument)
            ->create([
                'status' => DocumentStatus::AnalysisPending,
            ]);

        $action = app(
            CreateProjectContextSnapshot::class,
        );

        $first = $action->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        $replayed = $action->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        expect($replayed->id)
            ->toBe($first->id)
            ->and(
                ProjectContextSnapshot::query()->count(),
            )
            ->toBe(1)
            ->and($first->configuration_revision)
            ->toBe(1)
            ->and($first->identity_schema_version)
            ->toBe(
                ApprovedDocumentSetFingerprint::SCHEMA_VERSION,
            )
            ->and(
                $first
                    ->approved_document_set_fingerprint,
            )
            ->toMatch('/\A[0-9a-f]{64}\z/')
            ->and($first->approved_document_versions)
            ->toHaveCount(1);

        $entry =
            $first->approved_document_versions[0];

        expect($entry)
            ->document_id->toBe($document->id)
            ->document_version_id->toBe(
                $approvedVersion->id,
            )
            ->version->toBe(1)
            ->checksum_sha256->toBe(
                $approvedVersion->checksum_sha256,
            )
            ->parsed_content_checksum_sha256->toBe(
                hash(
                    'sha256',
                    'Immutable architecture content.',
                ),
            )
            ->parsed_content_storage_disk->toBe(
                'documents',
            )
            ->analysis_flags->toBe([
                'prompt_injection',
            ]);

        Storage::disk('documents')->assertExists(
            $entry['parsed_content_storage_path'],
        );

        expect(
            Storage::disk('documents')->get(
                $entry['parsed_content_storage_path'],
            ),
        )->toBe('Immutable architecture content.');
    },
);

test(
    'an approved document change creates a new snapshot without changing configuration',
    function (): void {
        $organization = Organization::factory()->create();

        $project = app(CreateProject::class)->handle(
            actorUserId: User::factory()->create()->id,
            organizationId: $organization->id,
            name: 'Approval-set project',
            description: null,
            projectType: ProjectType::WebApplication,
        );

        $firstDocument = Document::factory()
            ->for($project)
            ->create();

        DocumentVersion::factory()
            ->for($firstDocument)
            ->approved()
            ->create([
                'parsed_content' => 'First approved document.',
            ]);

        $action = app(
            CreateProjectContextSnapshot::class,
        );

        $first = $action->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        $secondDocument = Document::factory()
            ->for($project)
            ->create();

        DocumentVersion::factory()
            ->for($secondDocument)
            ->approved()
            ->create([
                'parsed_content' => 'Second approved document.',
            ]);

        $second = $action->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        expect($second->id)
            ->not->toBe($first->id)
            ->and(
                $second
                    ->project_configuration_version_id,
            )
            ->toBe(
                $first
                    ->project_configuration_version_id,
            )
            ->and($second->configuration_revision)
            ->toBe($first->configuration_revision)
            ->and(
                $second
                    ->approved_document_set_fingerprint,
            )
            ->not->toBe(
                $first
                    ->approved_document_set_fingerprint,
            )
            ->and(
                $first->approved_document_versions,
            )
            ->toHaveCount(1)
            ->and(
                $second->approved_document_versions,
            )
            ->toHaveCount(2)
            ->and(
                ProjectContextSnapshot::query()->count(),
            )
            ->toBe(2);
    },
);

test(
    'project context snapshots remain append only',
    function (): void {
        $organization = Organization::factory()->create();

        $project = app(CreateProject::class)->handle(
            actorUserId: User::factory()->create()->id,
            organizationId: $organization->id,
            name: 'Immutable snapshot project',
            description: null,
            projectType: ProjectType::WebApplication,
        );

        $snapshot = app(
            CreateProjectContextSnapshot::class,
        )->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        expect(
            fn (): bool => $snapshot
                ->forceFill([
                    'configuration_revision' => 2,
                ])
                ->save(),
        )->toThrow(
            LogicException::class,
            'Project context snapshots are immutable.',
        );

        expect(
            fn (): ?bool => $snapshot->delete(),
        )->toThrow(
            LogicException::class,
            'Project context snapshots are immutable.',
        );
    },
);
