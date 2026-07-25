<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Application\Documents\Contracts\DocumentParser;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

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
     * Validate upload metadata and require an active compatible parser.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(DocumentParser $documentParser): array
    {
        $supportedMediaTypes = $documentParser->supportedMediaTypes();

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
                'max:20480',
                function (
                    string $attribute,
                    mixed $value,
                    Closure $fail,
                ) use ($documentParser, $supportedMediaTypes): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $mediaType = $value->getMimeType();

                    if (
                        is_string($mediaType)
                        && $documentParser->supports($mediaType)
                    ) {
                        return;
                    }

                    $fail(sprintf(
                        'The document format is not supported. Supported media types: %s.',
                        implode(', ', $supportedMediaTypes),
                    ));
                },
            ],
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
