<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Application\Projects\BuildStartProjectCommand;
use App\Application\Shared\Commands\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StartProjectRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Starts one project planning workflow through the command bus.
 */
final class StartProjectController extends Controller
{
    /**
     * Build, dispatch, and report one authorized StartProject command.
     */
    public function __invoke(
        StartProjectRequest $request,
        Organization $organization,
        Project $project,
        BuildStartProjectCommand $buildCommand,
        CommandBus $commands,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 401);

        $result = $commands->dispatch($buildCommand->handle(
            organizationId: $organization->id,
            projectId: $project->id,
            requestedByUserId: $actor->id,
            idempotencyKey: (string) $request->validated('idempotency_key'),
        ));

        if (! $result->isSuccessful()) {
            return back()->withErrors([
                'project' => $result->message
                    ?? 'The project could not be started.',
            ]);
        }

        return back()->with('status', 'project-started');
    }
}
