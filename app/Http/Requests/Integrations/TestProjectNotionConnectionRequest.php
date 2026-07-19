<?php

declare(strict_types=1);

namespace App\Http\Requests\Integrations;

use App\Domain\Integrations\IntegrationProvider;
use App\Models\Project;
use App\Models\ProviderCredential;
use App\Rules\Integrations\ValidIntegrationCredentialSecret;
use App\Rules\Integrations\ValidNotionDatabaseId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Authorizes and validates a Notion connection-test request.
 */
final class TestProjectNotionConnectionRequest extends FormRequest
{
    /**
     * Permit only users authorized to manage project integrations.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can(
                'manageIntegrations',
                $project,
            ) === true;
    }

    /**
     * Validate the optional replacement credential and database reference.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * Never trim or normalize credentials. Whitespace is rejected by the
             * credential value object because it may indicate a copy error.
             */
            'credential' => [
                'nullable',
                'string',
                new ValidIntegrationCredentialSecret,
            ],

            'database_id' => [
                'bail',
                'required',
                'string',
                'max:2048',
                new ValidNotionDatabaseId,
            ],
        ];
    }

    /**
     * Require either a submitted credential or an existing stored credential.
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

                $submittedCredential =
                    $this->input('credential');

                if (
                    is_string($submittedCredential)
                    && $submittedCredential !== ''
                ) {
                    return;
                }

                $project = $this->route('project');

                if (! $project instanceof Project) {
                    return;
                }

                $credentialExists =
                    ProviderCredential::query()
                        ->forOrganization(
                            $project->organization_id,
                        )
                        ->forProject($project->id)
                        ->where(
                            'provider',
                            IntegrationProvider::Notion->value,
                        )
                        ->exists();

                if (! $credentialExists) {
                    $validator->errors()->add(
                        'credential',
                        'A Notion integration credential is required for the first connection test.',
                    );
                }
            },
        ];
    }

    /**
     * Return stable field labels.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'credential' => 'Notion integration credential',
            'database_id' => 'Notion database',
        ];
    }
}
