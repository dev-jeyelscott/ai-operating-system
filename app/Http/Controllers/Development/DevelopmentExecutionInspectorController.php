<?php

declare(strict_types=1);

namespace App\Http\Controllers\Development;

use App\Application\Development\GetDevelopmentExecutionInspector;
use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\Organization;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;

final class DevelopmentExecutionInspectorController extends Controller
{
    public function __invoke(
        Organization $organization,
        Project $project,
        string $execution,
        GetDevelopmentExecutionInspector $inspector,
    ): Response {
        Execution::query()
            ->forProject($project->id)
            ->whereKey($execution)
            ->whereIn('capability', ['development', 'development.execute'])
            ->firstOrFail(['id']);

        $read = fn (): array => $inspector->handle($organization->id, $project->id, $execution);

        return Inertia::render('projects/development/executions/show', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
            'project' => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug],
            'inspector' => Inertia::defer($read, rescue: true),
        ]);
    }
}
