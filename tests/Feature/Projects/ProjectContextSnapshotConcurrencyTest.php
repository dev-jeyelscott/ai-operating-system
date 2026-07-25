<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Application\Projects\CreateProject;
use App\Application\Projects\CreateProjectContextSnapshot;
use App\Domain\Projects\ProjectType;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\ProjectContextSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Storage;
use Tests\ProcessDatabaseTestCase;

/**
 * Verifies snapshot idempotency using real PostgreSQL row locking.
 */
final class ProjectContextSnapshotConcurrencyTest extends ProcessDatabaseTestCase
{
    /**
     * Concurrent identical requests must resolve to one persisted snapshot.
     */
    public function test_concurrent_identical_requests_return_one_snapshot(): void
    {
        config()->set(
            'filesystems.artifact',
            'local',
        );

        $organization = Organization::factory()->create();

        $project = app(CreateProject::class)->handle(
            actorUserId: User::factory()->create()->id,
            organizationId: $organization->id,
            name: 'Concurrent snapshot project',
            description: null,
            projectType: ProjectType::WebApplication,
        );

        $document = Document::factory()
            ->for($project)
            ->create();

        DocumentVersion::factory()
            ->for($document)
            ->approved()
            ->create([
                'parsed_content' => 'Concurrent immutable content.',
            ]);

        $snapshotIds = Concurrency::driver(
            'process',
        )->run([
            static fn (): int => self::createSnapshot(
                organizationId: $organization->id,
                projectId: $project->id,
            ),
            static fn (): int => self::createSnapshot(
                organizationId: $organization->id,
                projectId: $project->id,
            ),
        ]);

        self::assertCount(2, $snapshotIds);
        self::assertSame(
            $snapshotIds[0],
            $snapshotIds[1],
        );

        self::assertSame(
            1,
            ProjectContextSnapshot::query()
                ->where('project_id', $project->id)
                ->count(),
        );

        Storage::disk('local')->deleteDirectory(
            sprintf(
                'document-context/organizations/%d/projects/%d',
                $organization->id,
                $project->id,
            ),
        );
    }

    /**
     * Create one snapshot from an isolated process.
     */
    private static function createSnapshot(
        int $organizationId,
        int $projectId,
    ): int {
        config()->set(
            'filesystems.artifact',
            'local',
        );

        return app(
            CreateProjectContextSnapshot::class,
        )->handle(
            organizationId: $organizationId,
            projectId: $projectId,
        )->id;
    }
}
