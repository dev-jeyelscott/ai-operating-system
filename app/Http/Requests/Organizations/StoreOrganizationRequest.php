<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates external input for organization creation.
 */
final class StoreOrganizationRequest extends FormRequest
{
    /**
     * Allow authenticated users to create organizations.
     *
     * Organization-specific authorization does not apply because the
     * organization does not exist yet.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Define the organization creation validation contract.
     *
     * @return array<string, list<string>>
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
        ];
    }

    /**
     * Normalize whitespace before applying validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
        ]);
    }
}
