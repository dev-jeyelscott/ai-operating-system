<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Application\Documents\DocumentUploadInspector;
use App\Models\Project;
use App\Models\User;
use App\Rules\Documents\SafeProjectDocumentUpload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes and validates one replacement document upload.
 */
final class StoreReplacementDocumentVersionRequest extends FormRequest
{
    /**
     * Allow only project editors to submit a replacement.
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
     * Apply the same upload security contract as an initial version.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(
        DocumentUploadInspector $uploadInspector,
    ): array {
        return [
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
}
