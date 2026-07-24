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
     * Apply the same size and parser-capability rules as an initial upload.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(
        DocumentParser $documentParser,
    ): array {
        $supportedMediaTypes =
            $documentParser->supportedMediaTypes();

        return [
            'document' => [
                'bail',
                'required',
                'file',
                'max:20480',
                function (
                    string $attribute,
                    mixed $value,
                    Closure $fail,
                ) use (
                    $documentParser,
                    $supportedMediaTypes,
                ): void {
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
}
