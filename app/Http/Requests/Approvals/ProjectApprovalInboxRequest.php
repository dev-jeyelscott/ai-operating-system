<?php

declare(strict_types=1);

namespace App\Http\Requests\Approvals;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the read-only approval inbox filters.
 */
final class ProjectApprovalInboxRequest extends FormRequest
{
    /**
     * Allow authorization to remain on the tenant-scoped route policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return the supported approval inbox query rules.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => [
                'nullable',
                'string',
                Rule::in([
                    'all',
                    'roadmap',
                    'conflict',
                    'recovery',
                    'merge',
                ]),
            ],
            'urgency' => [
                'nullable',
                'string',
                Rule::in([
                    'all',
                    'overdue',
                    'expiring',
                    'normal',
                ]),
            ],
            'search' => [
                'nullable',
                'string',
                'max:120',
            ],
            'approval' => [
                'nullable',
                'ulid',
            ],
        ];
    }

    /**
     * Return normalized filters for the application query service.
     *
     * @return array{
     *     category: string,
     *     urgency: string,
     *     search: string,
     *     focusApproval: string|null
     * }
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'category' => is_string($validated['category'] ?? null)
                ? $validated['category']
                : 'all',
            'urgency' => is_string($validated['urgency'] ?? null)
                ? $validated['urgency']
                : 'all',
            'search' => is_string($validated['search'] ?? null)
                ? trim($validated['search'])
                : '',
            'focusApproval' => is_string($validated['approval'] ?? null)
                ? $validated['approval']
                : null,
        ];
    }
}
