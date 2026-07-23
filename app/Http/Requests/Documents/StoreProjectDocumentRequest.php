<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a single multipart document upload for a route-scoped project.
 */
final class StoreProjectDocumentRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_MEDIA_TYPES = [
        'application/pdf',
        'text/markdown',
        'text/plain',
    ];

    public function authorize(): bool
    {
        $user = $this->user();
        $project = $this->route('project');

        return $user instanceof User
            && $project instanceof Project
            && $user->can('update', $project);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => [
                'required',
                'string',
                'min:1',
                'max:191',
            ],
            'document_class' => [
                'nullable',
                'string',
                'max:100',
                'regex:/\A[a-z][a-z0-9_]{0,99}\z/D',
            ],
            'document' => [
                'required',
                'file',
                'mimetypes:'.implode(',', self::ALLOWED_MEDIA_TYPES),
                'max:20480',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'document_class' => trim((string) $this->input('document_class')),
        ]);
    }
}
