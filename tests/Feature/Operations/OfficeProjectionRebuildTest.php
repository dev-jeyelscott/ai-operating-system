<?php

declare(strict_types=1);

use App\Application\Operations\BuildOfficeProjection;
use App\Application\Operations\RebuildOfficeProjections;
use App\Domain\Executions\ExecutionStatus;
use App\Models\Execution;
use App\Models\OfficeProjection;
use App\Models\Roadmap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TicketTestFixture;

uses(RefreshDatabase::class);

/**
 * Mark one generated roadmap as approved for rebuild scenarios.
 */
function approveOfficeProjectionRebuildRoadmap(
    Roadmap $roadmap,
): void {
    $roadmap->forceFill([
        'status' => 'approved',
        'approved_fingerprint' => $roadmap->candidate_fingerprint,
        'approved_snapshot' => [
            'schema_version' => 1,
        ],
        'approved_at' => now(),
    ])->save();
}

it('reconstructs a deleted projection from durable project state', function (): void {
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-119-REBUILD',
    );

    approveOfficeProjectionRebuildRoadmap($fixture['roadmap']);

    $project = $fixture['project'];

    Execution::factory()
        ->for($project)
        ->create([
            'capability' => 'quality_assurance.simulation',
            'logical_role' => 'qa_engineer',
            'status' => ExecutionStatus::Running,
            'started_at' => now(),
        ]);

    $original = app(BuildOfficeProjection::class)->handle(
        organizationId: $project->organization_id,
        projectId: $project->id,
    );

    $expectedFingerprint = $original->fingerprint;

    OfficeProjection::query()
        ->whereKey($original->id)
        ->delete();

    $result = app(RebuildOfficeProjections::class)->handle(
        organizationId: $project->organization_id,
        projectId: $project->id,
        chunkSize: 25,
    );

    $rebuilt = OfficeProjection::query()
        ->forOrganization($project->organization_id)
        ->forProject($project->id)
        ->firstOrFail();

    expect($result['processed'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and($result['failures'])->toBe([])
        ->and($rebuilt->fingerprint)->toBe($expectedFingerprint)
        ->and($rebuilt->rebuilt_at)->not->toBeNull()
        ->and(OfficeProjection::query()->count())->toBe(1);
});

it('rebuilds only the explicitly selected project', function (): void {
    $selected = TicketTestFixture::create(
        stableId: 'AIOS-119-SELECTED',
    );

    $other = TicketTestFixture::create(
        stableId: 'AIOS-119-OTHER',
    );

    approveOfficeProjectionRebuildRoadmap($selected['roadmap']);
    approveOfficeProjectionRebuildRoadmap($other['roadmap']);

    $this->artisan('office:projections:rebuild', [
        '--organization' => $selected['project']->organization_id,
        '--project' => $selected['project']->id,
        '--chunk' => 25,
    ])->assertSuccessful();

    $this->assertDatabaseHas('office_projections', [
        'organization_id' => $selected['project']->organization_id,
        'project_id' => $selected['project']->id,
    ]);

    $this->assertDatabaseMissing('office_projections', [
        'organization_id' => $other['project']->organization_id,
        'project_id' => $other['project']->id,
    ]);
});

it('rejects an unsafe rebuild chunk size', function (): void {
    $this->artisan('office:projections:rebuild', [
        '--chunk' => 0,
    ])->assertFailed();
});
