<?php

declare(strict_types=1);

use App\Application\Projects\EvaluateProjectCompleteness;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ProjectConfiguration;

test('required document classes block preflight until an approved version exists', function (): void {
    [
        'organization' => $organization,
        'project' => $project,
    ] = completeProjectCompletenessFixture();

    ProjectConfiguration::query()
        ->where('project_id', $project->id)
        ->update([
            'required_documents' => json_encode(
                ['operations_runbook'],
                JSON_THROW_ON_ERROR,
            ),
        ]);

    $result = app(EvaluateProjectCompleteness::class)->handle(
        organizationId: $organization->id,
        projectId: $project->id,
    );

    expect($result->missingKeys())
        ->toContain('required_documents.operations_runbook');

    $document = Document::factory()
        ->for($project)
        ->create(['document_class' => 'operations_runbook']);
    DocumentVersion::factory()->for($document)->approved()->create();

    expect(app(EvaluateProjectCompleteness::class)->handle(
        organizationId: $organization->id,
        projectId: $project->id,
    )->isComplete())->toBeTrue();
});
