<?php

declare(strict_types=1);

namespace App\Http\Requests\Integrations;

use App\Domain\Integrations\IntegrationProvider;
use App\Models\Project;
use App\Models\ProviderCredential;
use App\Rules\Integrations\ValidIntegrationCredentialSecret;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Authorizes and validates a Codex preflight request.
 */
final class TestProjectCodexConnectionRequest extends FormRequest
{
    /**
     * Permit only users authorized to manage project integrations.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can('manageIntegrations', $project) === true;
    }

    /**
     * Validate a write-only candidate credential and explicit confirmation.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'credential' => [
                'nullable',
                'string',
                new ValidIntegrationCredentialSecret,
            ],
            'credential_confirmation' => [
                'nullable',
                'string',
                new ValidIntegrationCredentialSecret,
            ],
        ];
    }

    /**
     * Require a stored credential or a matching candidate/confirmation pair.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $credential = $this->input('credential');
                $confirmation = $this->input('credential_confirmation');

                if (is_string($credential) && $credential !== '') {
                    if (
                        ! is_string($confirmation)
                        || ! hash_equals($credential, $confirmation)
                    ) {
                        $validator->errors()->add(
                            'credential_confirmation',
                            'The Codex credential confirmation does not match.',
                        );
                    }

                    return;
                }

                $project = $this->route('project');

                if (! $project instanceof Project) {
                    return;
                }

                $exists = ProviderCredential::query()
                    ->forOrganization($project->organization_id)
                    ->forProject($project->id)
                    ->where('provider', IntegrationProvider::Codex->value)
                    ->exists();

                if (! $exists) {
                    $validator->errors()->add(
                        'credential',
                        'A Codex credential is required for the first preflight.',
                    );
                }
            },
        ];
    }

    /**
     * Return stable user-facing field labels.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'credential' => 'Codex credential',
            'credential_confirmation' => 'Codex credential confirmation',
        ];
    }
}
