<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes an explicit document review operation for the active project.
 */
final class ReviewDocumentVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can('update', $project) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
