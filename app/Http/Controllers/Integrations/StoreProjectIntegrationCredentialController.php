<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Application\Integrations\SaveProjectIntegrationCredential;
use App\Domain\Integrations\IntegrationProvider;
use App\Http\Requests\Integrations\StoreProjectIntegrationCredentialRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Handles the encrypted integration credential write command.
 */
final class StoreProjectIntegrationCredentialController
{
    /**
     * Store or rotate the credential and return without redisplaying it.
     */
    public function __invoke(
        StoreProjectIntegrationCredentialRequest $request,
        Organization $organization,
        Project $project,
        string $provider,
        SaveProjectIntegrationCredential $saveCredential,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $correlationId = $request->attributes->get('request_id');

        $saveCredential->handle(
            actorUserId: $user->id,
            organizationId: $organization->id,
            projectId: $project->id,
            provider: IntegrationProvider::from($provider),
            plaintextCredential: (string) $validated['credential'],
            correlationId: is_string($correlationId)
                ? $correlationId
                : null,
        );

        /*
         * Return only a status key. Do not flash the credential, ciphertext,
         * request payload, token prefix, or provider authorization header.
         */
        return to_route('organizations.projects.show', [
            'organization' => $organization,
            'project' => $project,
        ])->with('status', 'integration-credential-saved');
    }
}
