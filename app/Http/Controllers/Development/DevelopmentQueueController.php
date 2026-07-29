<?php

declare(strict_types=1);

namespace App\Http\Controllers\Development;

use App\Application\Development\ListProjectDevelopmentQueue;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;

final class DevelopmentQueueController extends Controller
{
    public function __invoke(
        Organization $organization,
        Project $project,
        ListProjectDevelopmentQueue $queue,
    ): Response {
        /** @var array<string, mixed>|null $snapshot */
        $snapshot = null;
        $read = function () use (&$snapshot, $organization, $project, $queue): array {
            return $snapshot ??= $queue->handle($organization->id, $project->id);
        };

        return Inertia::render('projects/development/index', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
            'project' => [
                'id' => $project->id, 'name' => $project->name, 'slug' => $project->slug,
                'status' => $project->status->value, 'terminal' => $project->status->isTerminal(),
            ],
            'projectUrl' => route('organizations.projects.show', compact('organization', 'project')),
            'queue' => Inertia::defer(function () use ($read): array {
                $data = $read();

                return [
                    'metadata' => $data['metadata'], 'workable' => $data['workable'],
                    'ineligible' => $data['ineligible'], 'retryScheduled' => $data['retryScheduled'],
                ];
            }, rescue: true),
            'leases' => Inertia::defer(fn (): array => $read()['activeLeases'], rescue: true),
        ]);
    }
}
