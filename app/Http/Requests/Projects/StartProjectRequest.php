<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes and validates one StartProject transport request.
 */
final class StartProjectRequest extends FormRequest
{
    /**
     * Verify the authenticated actor may start the route-bound project.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $project = $this->route('project');

        return $user instanceof User
            && $project instanceof Project
            && $user->can('start', $project);
    }

    /**
     * Define the idempotency contract for a StartProject submission.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => [
                'required',
                'string',
                'max:191',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/',
            ],
        ];
    }
}
