<?php

declare(strict_types=1);

use App\Application\Planning\Data\PlanningExecutionRequest;
use App\Application\Planning\Data\PlanningSourceReference;
use App\Application\Planning\PersistRoadmap;
use App\Application\Planning\PlanningResultValidator;
use App\Domain\Audit\AuditActorType;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Infrastructure\AgentProviders\SimulationPlanningProvider;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use App\Models\Roadmap;
use Illuminate\Support\Facades\DB;

/** @return array{project:Project,snapshot:ProjectContextSnapshot,execution:Execution,request:PlanningExecutionRequest} */
function roadmapPersistenceFixture(): array
{
    $project = Project::factory()->create();
    $document = Document::factory()->for($project)->create();
    $version = DocumentVersion::factory()->for($document)->approved()->create();
    $configuration = ProjectConfigurationVersion::query()->create([
        'project_id' => $project->id,
        'schema_version' => 2,
        'revision' => 1,
        'actor_type' => AuditActorType::System,
        'actor_id' => 'planning-test',
        'change_reason' => 'planning_test',
        'snapshot' => [
            'schema_version' => 2,
            'revision' => 1,
            'policy' => [
                'default_reasoning' => 'medium',
                'provider' => ['allowed_provider_ids' => ['simulation'], 'fallback_order' => ['simulation']],
                'budget' => ['limit_minor' => null, 'currency' => 'USD'],
                'approval' => ['roadmap_required' => true],
            ],
        ],
    ]);
    $documents = [[
        'document_id' => $document->id,
        'document_version_id' => $version->id,
        'version' => $version->version,
        'checksum_sha256' => $version->checksum_sha256,
    ]];
    $snapshot = ProjectContextSnapshot::query()->create([
        'project_id' => $project->id,
        'project_configuration_version_id' => $configuration->id,
        'configuration_revision' => 1,
        'identity_schema_version' => 1,
        'approved_document_set_fingerprint' => hash('sha256', 'planning-test-context'),
        'approved_document_versions' => $documents,
    ]);
    $execution = Execution::factory()->for($project)->create([
        'project_context_snapshot_id' => $snapshot->id,
        'capability' => 'planning.roadmap',
        'requested_reasoning_level' => ReasoningLevel::Medium,
    ]);
    $request = new PlanningExecutionRequest(
        projectId: $project->id,
        contextSnapshotId: $snapshot->id,
        contextFingerprint: $snapshot->approved_document_set_fingerprint,
        reasoningLevel: ReasoningLevel::Medium,
        documents: [new PlanningSourceReference(
            documentId: $document->id,
            documentVersionId: $version->id,
            version: $version->version,
            checksumSha256: $version->checksum_sha256,
        )],
        configurationVersionId: $configuration->id,
        configurationRevision: 1,
        configurationSchemaVersion: 2,
        configuration: $configuration->snapshot,
        policies: $configuration->snapshot['policy'],
    );

    return compact('project', 'snapshot', 'execution', 'request');
}

test('a complete typed roadmap round trips with context and traceability relationships', function (): void {
    $fixture = roadmapPersistenceFixture();
    $provider = new SimulationPlanningProvider;
    $result = $provider->execute($fixture['request']);
    app(PlanningResultValidator::class)->validate($result, $fixture['request']);

    $roadmap = app(PersistRoadmap::class)->handle($fixture['execution'], $fixture['request'], $result, $provider->id());
    $replayed = app(PersistRoadmap::class)->handle($fixture['execution'], $fixture['request'], $result, $provider->id());
    $roadmap->load(['contextSnapshot', 'tasks.traceabilityLinks.documentVersion']);

    expect($roadmap->goal)->toBe('Produce an approved, traceable delivery roadmap.')
        ->and($replayed->id)->toBe($roadmap->id)
        ->and($roadmap->contextSnapshot->is($fixture['snapshot']))->toBeTrue()
        ->and($roadmap->generated_snapshot['schema_version'])->toBe(1)
        ->and($roadmap->tasks)->toHaveCount(1)
        ->and($roadmap->tasks->first()->traceabilityLinks)->toHaveCount(1)
        ->and($roadmap->tasks->first()->traceabilityLinks->first()->checksum_sha256)->toBe($fixture['request']->documents[0]->checksumSha256);

    $this->assertDatabaseHas('roadmaps', [
        'project_id' => $fixture['project']->id,
        'planning_execution_id' => $fixture['execution']->id,
        'revision' => 1,
        'schema_version' => 1,
    ]);
});

test('regenerated roadmaps link to and preserve every earlier revision', function (): void {
    $fixture = roadmapPersistenceFixture();
    $provider = new SimulationPlanningProvider;
    $firstResult = $provider->execute($fixture['request']);
    $first = app(PersistRoadmap::class)->handle($fixture['execution'], $fixture['request'], $firstResult, $provider->id());

    $nextExecution = Execution::factory()->for($fixture['project'])->create([
        'project_context_snapshot_id' => $fixture['snapshot']->id,
        'capability' => 'planning.roadmap',
    ]);
    $nextRequest = new PlanningExecutionRequest(
        projectId: $fixture['project']->id,
        contextSnapshotId: $fixture['snapshot']->id,
        contextFingerprint: $fixture['snapshot']->approved_document_set_fingerprint,
        reasoningLevel: ReasoningLevel::Medium,
        documents: $fixture['request']->documents,
        seed: 2,
        feedbackFingerprint: hash('sha256', 'make it smaller'),
    );
    $second = app(PersistRoadmap::class)->handle($nextExecution, $nextRequest, $provider->execute($nextRequest), $provider->id());

    expect($second->revision)->toBe(2)
        ->and($second->parent_roadmap_id)->toBe($first->id)
        ->and($second->parent->is($first))->toBeTrue()
        ->and($first->children()->sole()->is($second))->toBeTrue()
        ->and(Roadmap::query()->where('project_id', $fixture['project']->id)->orderBy('revision')->pluck('id')->all())->toBe([$first->id, $second->id]);
});

test('persistence rejects execution and context ownership drift', function (): void {
    $fixture = roadmapPersistenceFixture();
    $provider = new SimulationPlanningProvider;
    $request = new PlanningExecutionRequest(
        projectId: Project::factory()->create()->id,
        contextSnapshotId: $fixture['snapshot']->id,
        contextFingerprint: $fixture['snapshot']->approved_document_set_fingerprint,
        reasoningLevel: ReasoningLevel::Medium,
        documents: $fixture['request']->documents,
    );

    app(PersistRoadmap::class)->handle($fixture['execution'], $request, $provider->execute($request), $provider->id());
})->throws(LogicException::class, 'ownership are inconsistent');

test('the database enforces project consistency for execution and context relationships', function (): void {
    $constraints = DB::table('pg_constraint')
        ->whereIn('conname', [
            'roadmaps_project_execution_consistency_foreign',
            'roadmaps_project_context_consistency_foreign',
            'roadmaps_parent_project_consistency_foreign',
            'roadmap_traceability_document_version_foreign',
            'task_dependencies_task_roadmap_foreign',
            'task_dependencies_depends_on_roadmap_foreign',
        ])
        ->pluck('conname')
        ->sort()
        ->values()
        ->all();

    expect($constraints)->toBe([
        'roadmap_traceability_document_version_foreign',
        'roadmaps_parent_project_consistency_foreign',
        'roadmaps_project_context_consistency_foreign',
        'roadmaps_project_execution_consistency_foreign',
        'task_dependencies_depends_on_roadmap_foreign',
        'task_dependencies_task_roadmap_foreign',
    ]);
});
