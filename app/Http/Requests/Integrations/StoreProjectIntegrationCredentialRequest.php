<?php

declare(strict_types=1);

namespace App\Http\Requests\Integrations;

use App\Domain\Integrations\IntegrationProvider;
use App\Models\Project;
use App\Rules\Integrations\ValidIntegrationCredentialSecret;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Authorizes and validates a generic integration credential write.
 */
final class StoreProjectIntegrationCredentialRequest extends FormRequest
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
     * Return the credential validation contract.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'credential' => [
                'required',
                'string',
                new ValidIntegrationCredentialSecret,
            ],
        ];
    }

    /**
     * Fail closed if a provider requiring preflight reaches this route.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $provider = (string) $this->route('provider');

                if (
                    ! in_array(
                        $provider,
                        IntegrationProvider::directCredentialWriteValues(),
                        true,
                    )
                ) {
                    $validator->errors()->add(
                        'credential',
                        'This provider requires its dedicated server-side preflight before a credential can be stored.',
                    );
                }
            },
        ];
    }

    /**
     * Return stable user-facing field names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['credential' => 'integration credential'];
    }
}
