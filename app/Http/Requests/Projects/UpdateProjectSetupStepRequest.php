<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Domain\Projects\Configuration\AutonomyLevel;
use App\Domain\Projects\Configuration\ProjectPolicyConfiguration;
use App\Domain\Projects\Configuration\ProviderPolicy;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Projects\Configuration\RepositoryProvider;
use App\Domain\Projects\Configuration\ValidationCommand;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\Project;
use App\Rules\Projects\ValidGitBranchName;
use App\Rules\Projects\ValidGitHubRepositoryUrl;
use App\Rules\Projects\ValidProjectValidationCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Authorizes and validates one project setup wizard step.
 */
final class UpdateProjectSetupStepRequest extends FormRequest
{
    /**
     * Project-configuration fields owned by the validation-command step.
     *
     * @var list<string>
     */
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
     * Return the validation rules for the active project setup step.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return match ($this->step()) {
            ProjectSetupStep::Details => $this->detailsRules(),
            ProjectSetupStep::Repository => $this->repositoryRules(),
            ProjectSetupStep::Commands => $this->validationCommandRules(),
            ProjectSetupStep::Policies => $this->policyRules(),
            ProjectSetupStep::Review => [
                'confirmation' => ['required', 'accepted'],
            ],
        };
    }

    /**
     * Return human-readable validation attribute names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
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
     * Return cross-field validators for policy configuration.
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
                /*
                * Nested structural errors are more actionable than a secondary
                * cross-field error, so only compare validated-shaped input.
                */
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $allowedProviderIds = $this->input(
                    'provider_policy.allowed_provider_ids',
                );

                $fallbackOrder = $this->input(
                    'provider_policy.fallback_order',
                );

                if (
                    ! is_array($allowedProviderIds)
                    || ! is_array($fallbackOrder)
                ) {
                    return;
                }

                /** @var list<string> $allowedProviderIds */
                /** @var list<string> $fallbackOrder */
                $disallowedFallbackProviders = array_values(
                    array_diff(
                        $fallbackOrder,
                        $allowedProviderIds,
                    ),
                );

                if ($disallowedFallbackProviders === []) {
                    return;
                }

                $validator->errors()->add(
                    'provider_policy.fallback_order',
                    sprintf(
                        'Fallback providers must be allowed first. Disallowed: %s.',
                        implode(', ', $disallowedFallbackProviders),
                    ),
                );
            },
        ];
    }

    /**
     * Trim project validation commands before rule evaluation and persistence.
     */
    private function prepareValidationCommands(): void
    {
        $normalized = [];

        foreach (self::VALIDATION_COMMAND_FIELDS as $field) {
            $value = $this->input($field);

            $normalized[$field] = is_string($value)
                ? trim($value)
                : $value;
        }

        $this->merge($normalized);
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
                    'infrastructure' => $this->commaSeparated(
                        'infrastructure',
                    ),
                    'package_managers' => $this->commaSeparated(
                        'package_managers',
                    ),
                    'runtimes' => $this->commaSeparated('runtimes'),
                ],
            ]);

            return;
        }

        if ($step === ProjectSetupStep::Repository) {
            /*
            * Normalize presentation-level whitespace and provider casing only.
            * URL semantics and branch syntax remain validation concerns.
            */
            $this->merge([
                'repository_provider' => strtolower(
                    trim((string) $this->input('repository_provider', '')),
                ),
                'repository_url' => trim(
                    (string) $this->input('repository_url', ''),
                ),
                'default_branch' => trim(
                    (string) $this->input('default_branch', ''),
                ),
                'integration_branch' => trim(
                    (string) $this->input('integration_branch', ''),
                ),
            ]);

            return;
        }

        if ($step === ProjectSetupStep::Commands) {
            $this->prepareValidationCommands();

            return;
        }

        if ($step === ProjectSetupStep::Policies) {
            $this->merge([
                'default_reasoning' => $this->lowercaseTrimmedInput(
                    'default_reasoning',
                ),
                'provider_policy' => [
                    'allowed_provider_ids' => $this->providerIds(
                        'allowed_provider_ids',
                    ),
                    'fallback_order' => $this->providerIds(
                        'fallback_order',
                    ),
                ],
                'budget_currency' => $this->uppercaseTrimmedInput(
                    'budget_currency',
                ),
                'autonomy_level' => $this->lowercaseTrimmedInput(
                    'autonomy_level',
                ),
                'approval_policy' => [
                    'roadmap_required' => $this->normalizedBooleanInput(
                        'roadmap_required',
                    ),
                    'ticket_execution_required' => $this->normalizedBooleanInput(
                        'ticket_execution_required',
                    ),
                    'merge_required' => $this->normalizedBooleanInput(
                        'merge_required',
                    ),
                ],
                'notification_policy' => [
                    'channels' => ['in_app'],
                    'events' => $this->commaSeparated(
                        'notification_events',
                    ),
                ],
            ]);
        }
    }

    /**
     * Return structural technology-stack validation.
     *
     * @return array<string, mixed>
     */
    private function detailsRules(): array
    {
        return [
            'technology_stack' => [
                'required',
                'array:languages,frameworks,databases,infrastructure,package_managers,runtimes',
            ],
            'technology_stack.languages' => [
                'required',
                'array',
                'min:1',
                'max:20',
            ],
            'technology_stack.languages.*' => [
                'required',
                'string',
                'max:100',
                'distinct',
            ],
            'technology_stack.frameworks' => ['required', 'array', 'max:30'],
            'technology_stack.frameworks.*' => [
                'string',
                'max:100',
                'distinct',
            ],
            'technology_stack.databases' => ['required', 'array', 'max:20'],
            'technology_stack.databases.*' => [
                'string',
                'max:100',
                'distinct',
            ],
            'technology_stack.infrastructure' => [
                'required',
                'array',
                'max:30',
            ],
            'technology_stack.infrastructure.*' => [
                'string',
                'max:100',
                'distinct',
            ],
            'technology_stack.package_managers' => [
                'required',
                'array',
                'max:10',
            ],
            'technology_stack.package_managers.*' => [
                'string',
                'max:100',
                'distinct',
            ],
            'technology_stack.runtimes' => [
                'required',
                'array',
                'max:20',
            ],
            'technology_stack.runtimes.*' => [
                'string',
                'max:100',
                'distinct',
            ],
        ];
    }

    /**
     * Return deterministic repository metadata validation.
     *
     * Validation checks syntax only. It does not perform DNS lookup, HTTP requests,
     * GitHub API requests, repository cloning, fetching, or remote writes.
     *
     * AIOS-024 remains responsible for enforcing the protected integration-branch
     * policy, including rejecting main as an automated target.
     *
     * @return array<string, mixed>
     */
    private function repositoryRules(): array
    {
        return [
            'repository_provider' => [
                'bail',
                'required',
                Rule::enum(RepositoryProvider::class),
            ],
            'repository_url' => [
                'bail',
                'required',
                'string',
                'max:2048',
                new ValidGitHubRepositoryUrl,
            ],
            'default_branch' => [
                'bail',
                'required',
                'string',
                'max:255',
                new ValidGitBranchName,
            ],
            'integration_branch' => [
                'bail',
                'required',
                'string',
                'max:255',
                new ValidGitBranchName,
            ],
        ];
    }

    /**
     * Return validation rules for explicit project policy configuration.
     *
     * @return array<string, list<mixed>>
     */
    private function policyRules(): array
    {
        return [
            'default_reasoning' => [
                'bail',
                'required',
                Rule::enum(ReasoningLevel::class),
            ],
            'provider_policy' => [
                'required',
                'array:allowed_provider_ids,fallback_order',
            ],
            'provider_policy.allowed_provider_ids' => [
                'bail',
                'required',
                'array',
                'min:1',
                'max:'.ProviderPolicy::MAX_PROVIDERS,
            ],
            'provider_policy.allowed_provider_ids.*' => [
                'bail',
                'required',
                'string',
                'max:'.ProviderPolicy::MAX_PROVIDER_ID_LENGTH,
                'regex:/\A[a-z][a-z0-9._-]{0,99}\z/D',
                'distinct:strict',
            ],
            'provider_policy.fallback_order' => [
                'bail',
                'required',
                'array',
                'min:1',
                'max:'.ProviderPolicy::MAX_PROVIDERS,
            ],
            'provider_policy.fallback_order.*' => [
                'bail',
                'required',
                'string',
                'max:'.ProviderPolicy::MAX_PROVIDER_ID_LENGTH,
                'regex:/\A[a-z][a-z0-9._-]{0,99}\z/D',
                'distinct:strict',
            ],
            'budget_limit_minor' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.ProjectPolicyConfiguration::MAX_BUDGET_LIMIT_MINOR,
            ],
            'budget_currency' => [
                'bail',
                'required',
                'string',
                'size:3',
                'regex:/\A[A-Z]{3}\z/D',
            ],
            'automatic_retry_limit' => [
                'bail',
                'required',
                'integer',
                'between:0,'.
                    ProjectPolicyConfiguration::MAX_AUTOMATIC_RETRY_LIMIT,
            ],
            'autonomy_level' => [
                'bail',
                'required',
                Rule::enum(AutonomyLevel::class),
            ],
            'approval_policy' => [
                'required',
                'array:roadmap_required,ticket_execution_required,merge_required',
            ],
            'approval_policy.roadmap_required' => [
                'required',
                'boolean',
            ],
            'approval_policy.ticket_execution_required' => [
                'required',
                'boolean',
            ],
            'approval_policy.merge_required' => [
                'required',
                'boolean',
            ],
            'notification_policy' => [
                'required',
                'array:channels,events',
            ],
            'notification_policy.channels' => [
                'required',
                'array',
                'size:1',
            ],
            'notification_policy.channels.0' => [
                'required',
                Rule::in(['in_app']),
            ],
            'notification_policy.events' => [
                'required',
                'array',
                'max:'.ProjectPolicyConfiguration::MAX_NOTIFICATION_EVENTS,
            ],
            'notification_policy.events.*' => [
                'bail',
                'required',
                'string',
                'max:'.
                    ProjectPolicyConfiguration::MAX_NOTIFICATION_EVENT_LENGTH,
                'distinct:strict',
            ],
        ];
    }

    /**
     * Resolve the route's validated backed-enum step.
     */
    private function step(): ProjectSetupStep
    {
        $step = $this->route('step');

        return $step instanceof ProjectSetupStep
            ? $step
            : ProjectSetupStep::from((string) $step);
    }

    /**
     * Normalize comma-separated provider identifiers without hiding duplicates.
     *
     * Duplicates are intentionally retained so the distinct validation rule can
     * reject ambiguous provider configuration.
     *
     * @return list<string>|mixed
     */
    private function providerIds(string $key): mixed
    {
        $value = $this->input($key, '');

        if (! is_string($value)) {
            return $value;
        }

        $providerIds = array_map(
            static fn (string $providerId): string => strtolower(
                trim($providerId),
            ),
            explode(',', $value),
        );

        return array_values(array_filter(
            $providerIds,
            static fn (string $providerId): bool => $providerId !== '',
        ));
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
            1,
            '1',
            'true',
            'on',
            'yes' => true,

            0,
            '0',
            'false',
            'off',
            'no' => false,

            default => $value,
        };
    }

    /**
     * Trim and lowercase a string input without coercing invalid types.
     */
    private function lowercaseTrimmedInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value)
            ? strtolower(trim($value))
            : $value;
    }

    /**
     * Trim and uppercase a string input without coercing invalid types.
     */
    private function uppercaseTrimmedInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value)
            ? strtoupper(trim($value))
            : $value;
    }

    /**
     * Convert one comma-separated string into a normalized unique list.
     *
     * @return list<string>
     */
    private function commaSeparated(string $key): array
    {
        $values = explode(
            ',',
            (string) $this->input($key, ''),
        );

        $normalized = array_map(
            static fn (string $value): string => trim($value),
            $values,
        );

        $filtered = array_filter(
            $normalized,
            static fn (string $value): bool => $value !== '',
        );

        return array_values(array_unique($filtered));
    }

    /**
     * Validate the complete set of project validation commands.
     *
     * Every command is required because project preflight must be able to report
     * the exact build, test, lint, static-analysis, and security procedures.
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
