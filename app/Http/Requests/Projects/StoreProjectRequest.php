<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Domain\Projects\ProjectType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and authorizes project creation input.
 */
final class StoreProjectRequest extends FormRequest
{
    /**
     * Authorize creation against the route-bound organization.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $this->route('organization');

        return $user instanceof User
            && $organization instanceof Organization
            && $user->can('createProject', $organization);
    }

    /**
     * Define the project creation validation contract.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:120',
            ],
            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'project_type' => [
                'required',
                'string',
                Rule::enum(ProjectType::class),
            ],
        ];
    }

    /**
     * Normalize user-controlled whitespace before validation.
     */
    protected function prepareForValidation(): void
    {
        $description = trim((string) $this->input('description'));

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => $description === ''
                ? null
                : $description,
        ]);
    }
}
