<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Application\Projects\UpdateProjectCodexProviderPolicy;
use App\Http\Requests\Integrations\UpdateProjectCodexPolicyRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Handles the project-scoped Codex provider-policy update command.
 */
final class UpdateProjectCodexPolicyController
{
    /**
     * Persist the validated policy without enabling provider execution.
     */
    public function __invoke(
        UpdateProjectCodexPolicyRequest $request,
        Organization $organization,
        Project $project,
        UpdateProjectCodexProviderPolicy $updatePolicy,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $correlationId = $request->attributes->get('request_id');

        $updatePolicy->handle(
            actorUserId: $user->id,
            organizationId: $organization->id,
            projectId: $project->id,
            codexPolicyPayload: $validated['codex_policy'],
            fallbackEnabled: (bool) $validated['fallback_enabled'],
            correlationId: is_string($correlationId)
                ? $correlationId
                : null,
        );

        return back()->with('status', 'codex-provider-policy-updated');
    }
}
