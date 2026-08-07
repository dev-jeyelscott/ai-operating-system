<?php

declare(strict_types=1);

namespace App\Http\Requests\QualityAssurance;

use App\Domain\QualityAssurance\MergeDecisionAction;
use App\Models\Project;
use App\Models\QaAssessment;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorizes and validates one simulated merge-decision submission.
 */
final class SubmitSimulatedMergeDecisionRequest extends FormRequest
{
    /**
     * Authorize the actor and enforce the project-assessment boundary.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $project = $this->route('project');
        $assessment = $this->route('assessment');

        return $user instanceof User
            && $project instanceof Project
            && $assessment instanceof QaAssessment
            && $assessment->project_id === $project->id
            && $user->can('approve', $project);
    }

    /**
     * Define the decision transport contract.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::enum(MergeDecisionAction::class),
            ],
            'expected_assessment_fingerprint' => [
                'required',
                'string',
                'size:64',
                'regex:/\A[a-f0-9]{64}\z/',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:191',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/',
            ],
            'reason' => [
                Rule::requiredIf(fn (): bool => in_array(
                    $this->input('action'),
                    [
                        MergeDecisionAction::RequestChanges->value,
                        MergeDecisionAction::Escalate->value,
                    ],
                    true,
                )),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * Normalize optional human reasoning before validation.
     */
    protected function prepareForValidation(): void
    {
        $reason = trim((string) $this->input('reason'));

        $this->merge([
            'reason' => $reason === '' ? null : $reason,
        ]);
    }
}
