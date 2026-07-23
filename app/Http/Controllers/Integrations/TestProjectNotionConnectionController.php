<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Application\Integrations\TestProjectNotionConnection;
use App\Application\Projects\SaveProjectSetupStep;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Projects\ProjectSetupStep;
use App\Http\Requests\Integrations\TestProjectNotionConnectionRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Handles project-scoped Notion connection tests.
 */
final class TestProjectNotionConnectionController
{
    /**
     * Test the connection and advance setup only after successful validation.
     */
    public function __invoke(
        TestProjectNotionConnectionRequest $request,
        Organization $organization,
        Project $project,
        TestProjectNotionConnection $testConnection,
        SaveProjectSetupStep $saveProjectSetupStep,
        TransactionManager $transactions,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $correlationId =
            $request->attributes->get('request_id');

        $credential = $validated['credential'] ?? null;

        $result = $transactions->run(
            function () use (
                $testConnection, $user, $organization, $project, $validated, $credential, $correlationId
            ) {
                return $testConnection->handle(
                    actorUserId: $user->id,
                    organizationId: $organization->id,
                    projectId: $project->id,
                    databaseReference: (string) $validated['database_id'],
                    plaintextCredential: is_string($credential)
                            ? $credential
                            : null,
                    correlationId: is_string($correlationId)
                            ? $correlationId
                            : null,
                );
            },
        );

        if (! $result->successful) {
            return back()
                ->withErrors([
                    'connection' => $result->userMessage(),
                ])
                ->with(
                    'status',
                    'notion-connection-test-failed',
                );
        }

        /*
         * The integration step may only be completed through a successful test.
         * It is intentionally excluded from the generic setup update endpoint.
         */
        $progress = $saveProjectSetupStep->handle(
            actorUserId: $user->id,
            organizationId: $organization->id,
            projectId: $project->id,
            step: ProjectSetupStep::Integrations,
            payload: [],
            correlationId: is_string($correlationId)
                    ? $correlationId
                    : null,
            externalConfigurationChanged: $result->configurationChanged,
        );

        if ($progress->isComplete()) {
            return to_route(
                'organizations.projects.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            )->with(
                'status',
                'notion-connection-test-succeeded',
            );
        }

        return to_route(
            'organizations.projects.setup.show',
            [
                'organization' => $organization,
                'project' => $project,
                'step' => $progress->current_step,
            ],
        )->with(
            'status',
            'notion-connection-test-succeeded',
        );
    }
}
