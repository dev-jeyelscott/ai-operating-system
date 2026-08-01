<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Application\Documents\DocumentUploadInspector;
use App\Models\Project;
use App\Models\User;
use App\Rules\Documents\SafeProjectDocumentUpload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a single multipart document upload for a route-scoped project.
 */
final class StoreProjectDocumentRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user may upload to the project.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $project = $this->route('project');

        return $user instanceof User
            && $project instanceof Project
            && $user->can('update', $project);
    }

    /**
     * Validate upload metadata and enforce the shared security contract.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(
        DocumentUploadInspector $uploadInspector,
    ): array {
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
                'bail',
                'required',
                'file',
                'max:'.$uploadInspector->maxKilobytes(),
                'extensions:'.implode(
                    ',',
                    $uploadInspector->allowedExtensions(),
                ),
                new SafeProjectDocumentUpload($uploadInspector),
            ],
        ];
    }

    /**
     * Return actionable validation messages without exposing internals.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.max' => 'The document may not be larger than 20 MB.',
            'document.extensions' => 'The document must use a .md or .txt extension.',
        ];
    }

    /**
     * Normalize free-text values before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'document_class' => trim(
                (string) $this->input('document_class'),
            ),
        ]);
    }
}
