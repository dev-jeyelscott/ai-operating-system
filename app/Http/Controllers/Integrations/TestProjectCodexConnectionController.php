<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Application\Integrations\TestProjectCodexConnection;
use App\Http\Requests\Integrations\TestProjectCodexConnectionRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Handles project-scoped Codex credential/model preflight.
 */
final class TestProjectCodexConnectionController
{
    /**
     * Execute the server-owned read-only Codex preflight operation.
     */
    public function __invoke(
        TestProjectCodexConnectionRequest $request,
        Organization $organization,
        Project $project,
        TestProjectCodexConnection $testConnection,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $correlationId = $request->attributes->get('request_id');
        $credential = $validated['credential'] ?? null;

        $result = $testConnection->handle(
            actorUserId: $user->id,
            organizationId: $organization->id,
            projectId: $project->id,
            plaintextCredential: is_string($credential)
                && $credential !== ''
                    ? $credential
                    : null,
            correlationId: is_string($correlationId)
                ? $correlationId
                : null,
        );

        if (! $result->successful) {
            return back()
                ->withErrors(['connection' => $result->userMessage()])
                ->with('status', 'codex-connection-test-failed');
        }

        return back()->with('status', 'codex-connection-test-succeeded');
    }
}
