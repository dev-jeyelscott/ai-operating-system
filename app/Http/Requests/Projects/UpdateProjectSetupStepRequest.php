<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Domain\Projects\Configuration\AutonomyLevel;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Projects\Configuration\RepositoryProvider;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\Project;
use App\Rules\Projects\ValidGitBranchName;
use App\Rules\Projects\ValidGitHubRepositoryUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorizes and validates one project setup wizard step.
 */
final class UpdateProjectSetupStepRequest extends FormRequest
{
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
     * Return rules for the active server-controlled wizard step.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->step()) {
            ProjectSetupStep::Details => $this->detailsRules(),
            ProjectSetupStep::Repository => $this->repositoryRules(),
            ProjectSetupStep::Commands => $this->commandRules(),
            ProjectSetupStep::Policies => $this->policyRules(),
            ProjectSetupStep::Review => [
                'confirmation' => ['required', 'accepted'],
            ],
        };
    }

    /**
     * Normalize comma-separated UI fields into structured configuration arrays.
     */
    protected function prepareForValidation(): void
    {
        if ($this->step() === ProjectSetupStep::Details) {
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
        }

        if ($this->step() === ProjectSetupStep::Repository) {
            /*
            * Normalize only presentation-level whitespace and provider casing.
            * URL semantics and branch syntax remain authoritative validation concerns.
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
        }

        if ($this->step() === ProjectSetupStep::Policies) {
            $this->merge([
                'budget_currency' => strtoupper(
                    trim((string) $this->input(
                        'budget_currency',
                        'USD',
                    )),
                ),
                'provider_policy' => [
                    'allowed_provider_ids' => $this->commaSeparated('allowed_provider_ids'),
                    'fallback_order' => $this->commaSeparated('fallback_order'),
                ],
                'approval_policy' => [
                    'roadmap_required' => $this->boolean('roadmap_required'),
                    'ticket_execution_required' => $this->boolean('ticket_execution_required'),
                    'merge_required' => $this->boolean('merge_required'),
                ],
                'notification_policy' => [
                    'channels' => ['in_app'],
                    'events' => $this->commaSeparated('notification_events'),
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
     * Return structural validation for configured project commands.
     *
     * The commands are stored only; AIOS-022 does not execute them.
     *
     * @return array<string, mixed>
     */
    private function commandRules(): array
    {
        return [
            'build_command' => ['required', 'string', 'max:2048'],
            'test_command' => ['required', 'string', 'max:2048'],
            'lint_command' => ['required', 'string', 'max:2048'],
            'static_analysis_command' => [
                'required',
                'string',
                'max:2048',
            ],
            'security_command' => ['required', 'string', 'max:2048'],
        ];
    }

    /**
     * Return structural policy validation.
     *
     * @return array<string, mixed>
     */
    private function policyRules(): array
    {
        return [
            'default_reasoning' => [
                'required',
                Rule::enum(ReasoningLevel::class),
            ],
            'provider_policy' => [
                'required',
                'array:allowed_provider_ids,fallback_order',
            ],
            'provider_policy.allowed_provider_ids' => [
                'required',
                'array',
                'min:1',
                'max:20',
            ],
            'provider_policy.allowed_provider_ids.*' => [
                'required',
                'string',
                'max:100',
                'distinct',
            ],
            'provider_policy.fallback_order' => [
                'required',
                'array',
                'max:20',
            ],
            'provider_policy.fallback_order.*' => [
                'string',
                'max:100',
                'distinct',
            ],
            'budget_limit_minor' => [
                'nullable',
                'integer',
                'min:0',
                'max:999999999999',
            ],
            'budget_currency' => [
                'required',
                'string',
                'size:3',
                'alpha',
            ],
            'automatic_retry_limit' => [
                'required',
                'integer',
                'between:0,10',
            ],
            'autonomy_level' => [
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
                'max:30',
            ],
            'notification_policy.events.*' => [
                'string',
                'max:100',
                'distinct',
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
}
