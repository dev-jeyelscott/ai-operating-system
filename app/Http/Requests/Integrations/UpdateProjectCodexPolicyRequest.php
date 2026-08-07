<?php

declare(strict_types=1);

namespace App\Http\Requests\Integrations;

use App\Domain\Executions\ExecutionCapability;
use App\Domain\Projects\Configuration\CodexProviderPolicy;
use App\Domain\Projects\Configuration\ProjectPolicyConfiguration;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorizes and validates the security-sensitive Codex provider policy form.
 */
final class UpdateProjectCodexPolicyRequest extends FormRequest
{
    /**
     * Permit only users authorized to manage project integrations.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can('manageIntegrations', $project) === true;
    }

    /**
     * Normalize browser fields into the domain policy shape.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'codex_policy' => [
                'enabled' => $this->booleanValue('enabled'),
                'model_identifier' => $this->trimmed('model_identifier'),
                'allowed_capabilities' => $this->commaSeparated(
                    'allowed_capabilities',
                ),
                'reasoning' => [
                    'minimum' => $this->lowercase('reasoning_minimum'),
                    'maximum' => $this->lowercase('reasoning_maximum'),
                ],
                'sandbox' => [
                    'planning' => $this->lowercase('sandbox_planning'),
                    'development' => $this->lowercase('sandbox_development'),
                    'quality_assurance' => $this->lowercase(
                        'sandbox_quality_assurance',
                    ),
                ],
                'network' => [
                    'default' => $this->lowercase('network_default'),
                    'allow_escalation_with_approval' => $this->booleanValue(
                        'network_allow_escalation_with_approval',
                    ),
                ],
                'budget_limit_minor' => $this->nullableIntegerInput(
                    'codex_budget_limit_minor',
                ),
                'timeout_seconds' => $this->input('timeout_seconds'),
                'retry_limit' => $this->input('retry_limit'),
            ],
            'fallback_enabled' => $this->booleanValue('fallback_enabled'),
        ]);
    }

    /**
     * Return strict policy validation before domain validation is run again.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $capabilities = array_map(
            static fn (ExecutionCapability $capability): string => $capability->value,
            ExecutionCapability::cases(),
        );

        return [
            'fallback_enabled' => ['required', 'boolean'],
            'codex_policy' => [
                'required',
                'array:enabled,model_identifier,allowed_capabilities,reasoning,sandbox,network,budget_limit_minor,timeout_seconds,retry_limit',
            ],
            'codex_policy.enabled' => ['required', 'boolean'],
            'codex_policy.model_identifier' => [
                'bail',
                'required',
                'string',
                'max:100',
                'regex:/\A[a-z0-9][a-z0-9._:-]{0,99}\z/D',
            ],
            'codex_policy.allowed_capabilities' => [
                'required',
                'array',
                'min:1',
                'max:3',
            ],
            'codex_policy.allowed_capabilities.*' => [
                'required',
                'string',
                Rule::in($capabilities),
                'distinct:strict',
            ],
            'codex_policy.reasoning' => [
                'required',
                'array:minimum,maximum',
            ],
            'codex_policy.reasoning.minimum' => [
                'required',
                Rule::enum(ReasoningLevel::class),
            ],
            'codex_policy.reasoning.maximum' => [
                'required',
                Rule::enum(ReasoningLevel::class),
            ],
            'codex_policy.sandbox' => [
                'required',
                'array:planning,development,quality_assurance',
            ],
            'codex_policy.sandbox.planning' => [
                'required',
                Rule::in(['read-only']),
            ],
            'codex_policy.sandbox.development' => [
                'required',
                Rule::in(['read-only', 'workspace-write']),
            ],
            'codex_policy.sandbox.quality_assurance' => [
                'required',
                Rule::in(['read-only']),
            ],
            'codex_policy.network' => [
                'required',
                'array:default,allow_escalation_with_approval',
            ],
            'codex_policy.network.default' => [
                'required',
                Rule::in(['deny']),
            ],
            'codex_policy.network.allow_escalation_with_approval' => [
                'required',
                'boolean',
            ],
            'codex_policy.budget_limit_minor' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.ProjectPolicyConfiguration::MAX_BUDGET_LIMIT_MINOR,
            ],
            'codex_policy.timeout_seconds' => [
                'required',
                'integer',
                'between:'.CodexProviderPolicy::MIN_TIMEOUT_SECONDS.','.
                    CodexProviderPolicy::MAX_TIMEOUT_SECONDS,
            ],
            'codex_policy.retry_limit' => [
                'required',
                'integer',
                'between:0,'.CodexProviderPolicy::MAX_RETRY_LIMIT,
            ],
        ];
    }

    /**
     * Return human-readable labels for nested policy fields.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'codex_policy.model_identifier' => 'Codex model identifier',
            'codex_policy.allowed_capabilities' => 'Codex capabilities',
            'codex_policy.reasoning.minimum' => 'minimum Codex reasoning',
            'codex_policy.reasoning.maximum' => 'maximum Codex reasoning',
            'codex_policy.budget_limit_minor' => 'Codex budget limit',
            'codex_policy.timeout_seconds' => 'Codex timeout',
            'codex_policy.retry_limit' => 'Codex retry limit',
        ];
    }

    /**
     * Normalize one HTML boolean while preserving invalid values for validation.
     */
    private function booleanValue(string $key): mixed
    {
        $value = $this->input($key);

        if (is_bool($value)) {
            return $value;
        }

        return match ($value) {
            1, '1', 'true', 'on', 'yes' => true,
            0, '0', 'false', 'off', 'no' => false,
            default => $value,
        };
    }

    /**
     * Trim one string without coercing invalid input types.
     */
    private function trimmed(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Trim and lowercase one string without coercing invalid input types.
     */
    private function lowercase(string $key): mixed
    {
        $value = $this->trimmed($key);

        return is_string($value) ? strtolower($value) : $value;
    }

    /**
     * Convert a comma-separated field into a normalized list.
     *
     * @return list<string>
     */
    private function commaSeparated(string $key): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) $this->input($key, '')),
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * Normalize an optional integer field while preserving invalid values.
     */
    private function nullableIntegerInput(string $key): mixed
    {
        $value = $this->input($key);

        return $value === '' ? null : $value;
    }
}
