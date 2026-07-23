<?php

declare(strict_types=1);

namespace App\Http\Requests\Integrations;

use App\Models\Project;
use App\Rules\Integrations\ValidIntegrationCredentialSecret;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes and validates an integration credential write.
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
            && $this->user()?->can(
                'manageIntegrations',
                $project,
            ) === true;
    }

    /**
     * Return the credential validation contract.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * Do not use prepareForValidation() or trim() here. Silent mutation
             * could turn an invalid copied token into a different stored token.
             */
            'credential' => [
                'required',
                'string',
                new ValidIntegrationCredentialSecret,
            ],
        ];
    }

    /**
     * Return stable user-facing field names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'credential' => 'integration credential',
        ];
    }
}
