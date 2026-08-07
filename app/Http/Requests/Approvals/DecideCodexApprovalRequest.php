<?php

declare(strict_types=1);

namespace App\Http\Requests\Approvals;

use App\Domain\Codex\CodexApprovalAction;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and authorizes one human Codex approval decision.
 */
final class DecideCodexApprovalRequest extends FormRequest
{
    /**
     * Require the existing project approval permission.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can('approve', $project) === true;
    }

    /**
     * Validate only the provider-independent user decision fields.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::enum(CodexApprovalAction::class),
            ],

            'reason' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
