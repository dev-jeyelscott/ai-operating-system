<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Application\Integrations\TestProjectNotionConnection;
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
     * Execute the application-owned Notion connection operation.
     */
    public function __invoke(
        TestProjectNotionConnectionRequest $request,
        Organization $organization,
        Project $project,
        TestProjectNotionConnection $testConnection,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();

        $correlationId =
            $request->attributes->get('request_id');

        $credential = $validated['credential'] ?? null;

        /*
         * The application command owns provider validation and every resulting
         * local transaction. The controller must not coordinate transactions or
         * setup persistence.
         */
        $result = $testConnection->handle(
            actorUserId: $user->id,
            organizationId: $organization->id,
            projectId: $project->id,
            databaseReference: (string) $validated['database_id'],
            plaintextCredential: is_string($credential)
                    ? $credential
                    : null,
            selectedDataSourceId: isset($validated['data_source_id'])
                    ? (string) $validated['data_source_id']
                    : null,
            correlationId: is_string($correlationId)
                    ? $correlationId
                    : null,
        );

        if ($result->dataSourceCandidates !== []) {
            return back()
                ->withErrors(['data_source_id' => $result->userMessage()])
                ->with('notion_data_source_candidates', $result->dataSourceCandidates)
                ->with('status', 'notion-data-source-selection-required');
        }

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
         * The command has already committed setup progress atomically. This
         * query only determines the next redirect and performs no mutation.
         */
        $progress = $project->setupProgress()
            ->firstOrFail();

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
