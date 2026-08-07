<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Domain\Projects\Configuration\AutonomyLevel;
use App\Domain\Projects\Configuration\CodexProviderPolicy;
use App\Domain\Projects\Configuration\ProjectPolicyConfiguration;
use App\Domain\Projects\Configuration\ProviderPolicy;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Projects\Configuration\RepositoryProvider;
use App\Domain\Projects\Configuration\ValidationCommand;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Rules\Projects\ValidGitBranchName;
use App\Rules\Projects\ValidGitHubRepositoryUrl;
use App\Rules\Projects\ValidProjectValidationCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Authorizes and validates one project setup wizard step.
 */
final class UpdateProjectSetupStepRequest extends FormRequest
{
    /** @var list<string> */
    private const VALIDATION_COMMAND_FIELDS = [
        'build_command',
        'test_command',
        'lint_command',
        'static_analysis_command',
        'security_command',
    ];

    /**
     * Authorize configuration through the existing project update policy.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can('update', $project) === true;
    }

    /**
     * Return validation rules for the active setup step.
     *
     * @return array<string, list<mixed>|mixed>
     */
    public function rules(): array
    {
        return match ($this->step()) {
            ProjectSetupStep::Details => $this->detailsRules(),
            ProjectSetupStep::Repository => $this->repositoryRules(),
            ProjectSetupStep::Integrations => [],
            ProjectSetupStep::Commands => $this->validationCommandRules(),
            ProjectSetupStep::Policies => $this->policyRules(),
            ProjectSetupStep::Review => [
                'confirmation' => ['required', 'accepted'],
            ],
        };
    }

    /**
     * Return stable field labels.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'required_documents' => 'required documents',
            'required_documents.*' => 'required document identifier',
            'build_command' => 'build command',
            'test_command' => 'test command',
            'lint_command' => 'lint command',
            'static_analysis_command' => 'static-analysis command',
            'security_command' => 'security command',
            'default_reasoning' => 'default reasoning',
            'provider_policy.allowed_provider_ids' => 'allowed providers',
            'provider_policy.allowed_provider_ids.*' => 'provider identifier',
            'provider_policy.fallback_order' => 'provider fallback order',
            'provider_policy.fallback_order.*' => 'fallback provider',
            'budget_limit_minor' => 'budget limit',
            'budget_currency' => 'budget currency',
            'automatic_retry_limit' => 'automatic retry limit',
            'autonomy_level' => 'autonomy level',
            'approval_policy.roadmap_required' => 'roadmap approval requirement',
            'approval_policy.ticket_execution_required' => 'ticket execution approval requirement',
            'approval_policy.merge_required' => 'merge approval requirement',
            'notification_policy.events' => 'notification events',
            'notification_policy.events.*' => 'notification event',
        ];
    }

    /**
     * Return cross-field validators for provider policy configuration.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        if ($this->step() !== ProjectSetupStep::Policies) {
            return [];
        }

        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $policy = $this->input('provider_policy');

                if (! is_array($policy)) {
                    return;
                }

                /*
             * Surface fallback membership errors on the fallback field itself.
             *
             * ProviderPolicy remains the authoritative domain invariant below;
             * this HTTP-level check only ensures the validation error is
             * associated with the field the user must correct.
             */
                $allowedProviderIds =
                    $policy['allowed_provider_ids'] ?? null;
                $fallbackOrder =
                    $policy['fallback_order'] ?? null;

                if (
                    is_array($allowedProviderIds)
                    && array_is_list($allowedProviderIds)
                    && is_array($fallbackOrder)
                    && array_is_list($fallbackOrder)
                ) {
                    foreach ($fallbackOrder as $fallbackProvider) {
                        if (
                            ! is_string($fallbackProvider)
                            || in_array(
                                $fallbackProvider,
                                $allowedProviderIds,
                                true,
                            )
                        ) {
                            continue;
                        }

                        $validator->errors()->add(
                            'provider_policy.fallback_order',
                            sprintf(
                                'Fallback provider [%s] is not in the provider allowlist.',
                                $fallbackProvider,
                            ),
                        );

                        return;
                    }
                }

                try {
                    $providerPolicy = ProviderPolicy::fromArray(
                        $policy,
                    );

                    $defaultReasoning = ReasoningLevel::from(
                        (string) $this->input(
                            'default_reasoning',
                        ),
                    );

                    $budget = $this->input(
                        'budget_limit_minor',
                    );

                    $providerPolicy->codex->assertWithinProjectPolicy(
                        projectDefaultReasoning: $defaultReasoning,
                        projectBudgetLimitMinor: $budget === null || $budget === ''
                            ? null
                            : (int) $budget,
                        projectAutomaticRetryLimit: (int) $this->input(
                            'automatic_retry_limit',
                        ),
                    );
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add(
                        'provider_policy.allowed_provider_ids',
                        $exception->getMessage(),
                    );
                }
            },
        ];
    }

    /**
     * Normalize presentation-level input before validation and persistence.
     */
    protected function prepareForValidation(): void
    {
        $step = $this->step();

        if ($step === ProjectSetupStep::Details) {
            $this->merge([
                'technology_stack' => [
                    'languages' => $this->commaSeparated('languages'),
                    'frameworks' => $this->commaSeparated('frameworks'),
                    'databases' => $this->commaSeparated('databases'),
                    'infrastructure' => $this->commaSeparated('infrastructure'),
                    'package_managers' => $this->commaSeparated('package_managers'),
                    'runtimes' => $this->commaSeparated('runtimes'),
                ],
            ]);

            return;
        }

        if ($step === ProjectSetupStep::Repository) {
            $this->merge([
                'repository_provider' => strtolower(trim((string) $this->input('repository_provider', ''))),
                'repository_url' => trim((string) $this->input('repository_url', '')),
                'default_branch' => trim((string) $this->input('default_branch', '')),
                'integration_branch' => trim((string) $this->input('integration_branch', '')),
            ]);

            return;
        }

        if ($step === ProjectSetupStep::Commands) {
            $normalized = [];

            foreach (self::VALIDATION_COMMAND_FIELDS as $field) {
                $value = $this->input($field);
                $normalized[$field] = is_string($value) ? trim($value) : $value;
            }

            $this->merge($normalized);

            return;
        }

        if ($step !== ProjectSetupStep::Policies) {
            return;
        }

        /*
         * The generic Policies screen owns allowlist/fallback values only. The
         * security-sensitive Codex sub-policy is preserved from persistence and
         * can only be edited through the dedicated Codex integration form.
         */
        $this->merge([
            'required_documents' => $this->commaSeparated('required_documents'),
            'default_reasoning' => $this->lowercaseTrimmedInput('default_reasoning'),
            'provider_policy' => [
                'allowed_provider_ids' => $this->providerIds('allowed_provider_ids'),
                'fallback_order' => $this->providerIds('fallback_order'),
                'codex' => $this->persistedCodexPolicy(),
            ],
            'budget_currency' => $this->uppercaseTrimmedInput('budget_currency'),
            'autonomy_level' => $this->lowercaseTrimmedInput('autonomy_level'),
            'approval_policy' => [
                'roadmap_required' => $this->normalizedBooleanInput('roadmap_required'),
                'ticket_execution_required' => $this->normalizedBooleanInput('ticket_execution_required'),
                'merge_required' => $this->normalizedBooleanInput('merge_required'),
            ],
            'notification_policy' => [
                'channels' => ['in_app'],
                'events' => $this->commaSeparated('notification_events'),
            ],
        ]);
    }

    /**
     * Return structural technology-stack validation.
     *
     * @return array<string, mixed>
     */
    private function detailsRules(): array
    {
        return [
            'technology_stack' => ['required', 'array:languages,frameworks,databases,infrastructure,package_managers,runtimes'],
            'technology_stack.languages' => ['required', 'array', 'min:1', 'max:20'],
            'technology_stack.languages.*' => ['required', 'string', 'max:100', 'distinct'],
            'technology_stack.frameworks' => ['required', 'array', 'max:30'],
            'technology_stack.frameworks.*' => ['string', 'max:100', 'distinct'],
            'technology_stack.databases' => ['required', 'array', 'max:20'],
            'technology_stack.databases.*' => ['string', 'max:100', 'distinct'],
            'technology_stack.infrastructure' => ['required', 'array', 'max:30'],
            'technology_stack.infrastructure.*' => ['string', 'max:100', 'distinct'],
            'technology_stack.package_managers' => ['required', 'array', 'max:10'],
            'technology_stack.package_managers.*' => ['string', 'max:100', 'distinct'],
            'technology_stack.runtimes' => ['required', 'array', 'max:20'],
            'technology_stack.runtimes.*' => ['string', 'max:100', 'distinct'],
        ];
    }

    /**
     * Return deterministic repository metadata validation.
     *
     * @return array<string, mixed>
     */
    private function repositoryRules(): array
    {
        return [
            'repository_provider' => ['bail', 'required', Rule::enum(RepositoryProvider::class)],
            'repository_url' => ['bail', 'required', 'string', 'max:2048', new ValidGitHubRepositoryUrl],
            'default_branch' => ['bail', 'required', 'string', 'max:255', new ValidGitBranchName],
            'integration_branch' => ['bail', 'required', 'string', 'max:255', new ValidGitBranchName, Rule::notIn(['main'])],
        ];
    }

    /**
     * Return custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'integration_branch.not_in' => 'The integration branch cannot be main.',
        ];
    }

    /**
     * Return project policy validation.
     *
     * @return array<string, list<mixed>>
     */
    private function policyRules(): array
    {
        return [
            'required_documents' => ['bail', 'required', 'array', 'min:1', 'max:20'],
            'required_documents.*' => ['bail', 'required', 'string', 'max:100', 'regex:/\A[a-z][a-z0-9_]{0,99}\z/D', 'distinct:strict'],
            'default_reasoning' => ['bail', 'required', Rule::enum(ReasoningLevel::class)],
            'provider_policy' => ['required', 'array:allowed_provider_ids,fallback_order,codex'],
            'provider_policy.allowed_provider_ids' => ['bail', 'required', 'array', 'min:1', 'max:'.ProviderPolicy::MAX_PROVIDERS],
            'provider_policy.allowed_provider_ids.*' => ['bail', 'required', 'string', 'max:'.ProviderPolicy::MAX_PROVIDER_ID_LENGTH, 'regex:/\A[a-z][a-z0-9._-]{0,99}\z/D', 'distinct:strict'],
            'provider_policy.fallback_order' => ['bail', 'required', 'array', 'min:1', 'max:'.ProviderPolicy::MAX_PROVIDERS],
            'provider_policy.fallback_order.*' => ['bail', 'required', 'string', 'max:'.ProviderPolicy::MAX_PROVIDER_ID_LENGTH, 'regex:/\A[a-z][a-z0-9._-]{0,99}\z/D', 'distinct:strict'],
            'provider_policy.codex' => ['required', 'array'],
            'budget_limit_minor' => ['nullable', 'integer', 'min:0', 'max:'.ProjectPolicyConfiguration::MAX_BUDGET_LIMIT_MINOR],
            'budget_currency' => ['bail', 'required', 'string', 'size:3', 'regex:/\A[A-Z]{3}\z/D'],
            'automatic_retry_limit' => ['bail', 'required', 'integer', 'between:0,'.ProjectPolicyConfiguration::MAX_AUTOMATIC_RETRY_LIMIT],
            'autonomy_level' => ['bail', 'required', Rule::enum(AutonomyLevel::class)],
            'approval_policy' => ['required', 'array:roadmap_required,ticket_execution_required,merge_required'],
            'approval_policy.roadmap_required' => ['required', 'boolean'],
            'approval_policy.ticket_execution_required' => ['required', 'boolean'],
            'approval_policy.merge_required' => ['required', 'boolean'],
            'notification_policy' => ['required', 'array:channels,events'],
            'notification_policy.channels' => ['required', 'array', 'size:1'],
            'notification_policy.channels.0' => ['required', Rule::in(['in_app'])],
            'notification_policy.events' => ['required', 'array', 'max:'.ProjectPolicyConfiguration::MAX_NOTIFICATION_EVENTS],
            'notification_policy.events.*' => ['bail', 'required', 'string', 'max:'.ProjectPolicyConfiguration::MAX_NOTIFICATION_EVENT_LENGTH, 'distinct:strict'],
        ];
    }

    /**
     * Return the current persisted Codex policy so the generic policy form
     * cannot silently reset security-sensitive fields it does not render.
     *
     * @return array<array-key, mixed>
     */
    private function persistedCodexPolicy(): array
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            return CodexProviderPolicy::defaults();
        }

        $configuration = ProjectConfiguration::query()
            ->where('project_id', $project->id)
            ->first();
        $policy = $configuration?->provider_policy['codex'] ?? null;

        return is_array($policy)
            ? $policy
            : CodexProviderPolicy::defaults();
    }

    /**
     * Resolve the route's backed-enum setup step.
     */
    private function step(): ProjectSetupStep
    {
        $step = $this->route('step');

        return $step instanceof ProjectSetupStep
            ? $step
            : ProjectSetupStep::from((string) $step);
    }

    /**
     * Normalize comma-separated provider identifiers while retaining duplicates.
     */
    private function providerIds(string $key): mixed
    {
        $value = $this->input($key, '');

        if (! is_string($value)) {
            return $value;
        }

        return array_values(array_filter(array_map(
            static fn (string $providerId): string => strtolower(trim($providerId)),
            explode(',', $value),
        ), static fn (string $providerId): bool => $providerId !== ''));
    }

    /**
     * Normalize a known HTML boolean while preserving invalid values.
     */
    private function normalizedBooleanInput(string $key): mixed
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
     * Trim and lowercase a string input without coercing invalid types.
     */
    private function lowercaseTrimmedInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? strtolower(trim($value)) : $value;
    }

    /**
     * Trim and uppercase a string input without coercing invalid types.
     */
    private function uppercaseTrimmedInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? strtoupper(trim($value)) : $value;
    }

    /**
     * Convert one comma-separated string into a normalized unique list.
     *
     * @return list<string>
     */
    private function commaSeparated(string $key): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) $this->input($key, '')),
        ), static fn (string $value): bool => $value !== '')));
    }

    /**
     * Validate the complete set of project validation commands.
     *
     * @return array<string, list<mixed>>
     */
    private function validationCommandRules(): array
    {
        $rules = static fn (): array => [
            'bail',
            'required',
            'string',
            'max:'.ValidationCommand::MAX_LENGTH,
            new ValidProjectValidationCommand,
        ];

        return [
            'build_command' => $rules(),
            'test_command' => $rules(),
            'lint_command' => $rules(),
            'static_analysis_command' => $rules(),
            'security_command' => $rules(),
        ];
    }
}
