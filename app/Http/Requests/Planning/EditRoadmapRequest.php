<?php

declare(strict_types=1);

namespace App\Http\Requests\Planning;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EditRoadmapRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_content_version' => ['required', 'integer', 'min:1'],
            'expected_fingerprint' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'idempotency_key' => ['required', 'string', 'max:191', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/'],
            'patch' => ['required', 'array'],
            'patch.roadmap' => ['sometimes', 'array'],
            'patch.roadmap.goal' => ['sometimes', 'string', 'max:2000'],
            'patch.roadmap.scope' => ['sometimes', 'array', 'min:1'],
            'patch.roadmap.scope.*' => ['string', 'max:2000'],
            'patch.roadmap.assumptions' => ['sometimes', 'array'],
            'patch.roadmap.assumptions.*' => ['string', 'max:2000'],
            'patch.roadmap.constraints' => ['sometimes', 'array', 'min:1'],
            'patch.roadmap.constraints.*' => ['string', 'max:2000'],
            'patch.roadmap.definition_of_done' => ['sometimes', 'array', 'min:1'],
            'patch.roadmap.definition_of_done.*' => ['string', 'max:2000'],
            'patch.tasks' => ['sometimes', 'array'],
            'patch.tasks.*' => ['array'],
            'patch.tasks.*.title' => ['sometimes', 'string', 'max:500'],
            'patch.tasks.*.objective' => ['sometimes', 'string', 'max:2000'],
            'patch.tasks.*.scope' => ['sometimes', 'array:included,excluded'],
            'patch.tasks.*.scope.included' => ['required_with:patch.tasks.*.scope', 'array', 'min:1'],
            'patch.tasks.*.scope.included.*' => ['string', 'max:2000'],
            'patch.tasks.*.scope.excluded' => ['required_with:patch.tasks.*.scope', 'array'],
            'patch.tasks.*.scope.excluded.*' => ['string', 'max:2000'],
            'patch.tasks.*.acceptance_criteria' => ['sometimes', 'array', 'min:1'],
            'patch.tasks.*.acceptance_criteria.*' => ['required', 'string', 'max:2000'],
            'patch.tasks.*.evidence_requirements' => ['sometimes', 'array', 'min:1'],
            'patch.tasks.*.evidence_requirements.*' => ['string', 'max:2000'],
            'patch.tasks.*.priority' => ['sometimes', 'in:low,medium,high,critical'],
            'patch.tasks.*.risk' => ['sometimes', 'in:low,medium,high,critical'],
            'patch.tasks.*.reasoning' => ['sometimes', 'string', 'max:4000'],
            'patch.tasks.*.logical_agent' => ['sometimes', 'in:product_manager,business_analyst,project_manager,software_architect,security_architect,database_architect,documentation_analyst,backend_engineer,frontend_engineer,database_engineer,test_engineer,devops_engineer'],
            'patch.tasks.*.estimated_complexity' => ['sometimes', 'integer', 'between:1,13'],
            'patch.tasks.*.human_approval_required' => ['sometimes', 'boolean'],
        ];
    }
}
