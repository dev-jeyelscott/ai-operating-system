<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Domain\Documents\DocumentStatus;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Domain\Projects\Configuration\CodexProviderPolicy;
use App\Domain\Projects\Configuration\Exceptions\InvalidValidationCommand;
use App\Domain\Projects\Configuration\GitBranchName;
use App\Domain\Projects\Configuration\GitHubRepositoryUrl;
use App\Domain\Projects\Configuration\ProjectCompletenessIssue;
use App\Domain\Projects\Configuration\ProjectCompletenessResult;
use App\Domain\Projects\Configuration\ProjectPolicyConfiguration;
use App\Domain\Projects\Configuration\ProviderPolicy;
use App\Domain\Projects\Configuration\ValidationCommand;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectIntegration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use InvalidArgumentException;

/**
 * Evaluates persisted project setup without mutating data or calling providers.
 */
final readonly class EvaluateProjectCompleteness
{
    /**
     * Return every configuration blocker in wizard-remediation order.
     */
    public function handle(int $organizationId, int $projectId): ProjectCompletenessResult
    {
        $this->assertPositiveIdentifier($organizationId, 'organization');
        $this->assertPositiveIdentifier($projectId, 'project');

        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();
        $configuration = ProjectConfiguration::query()
            ->where('project_id', $project->id)
            ->first();

        if ($configuration === null) {
            return ProjectCompletenessResult::forProject(
                projectId: $project->id,
                issues: [$this->issue(
                    'configuration.record',
                    ProjectSetupStep::Details,
                    'The project configuration record is missing.',
                    'Open project setup and save the Technology stack step to initialize the configuration.',
                )],
            );
        }

        if (! $configuration->usesCurrentSchema()) {
            return ProjectCompletenessResult::forProject(
                projectId: $project->id,
                issues: [$this->issue(
                    'configuration.schema_version',
                    ProjectSetupStep::Review,
                    sprintf('Project configuration schema version %d is unsupported.', $configuration->schema_version),
                    'Migrate or upcast the project configuration before running project preflight.',
                )],
            );
        }

        $notionCredential = $this->credentialMetadata(
            $organizationId,
            $project->id,
            IntegrationProvider::Notion,
        );
        $codexCredential = $this->credentialMetadata(
            $organizationId,
            $project->id,
            IntegrationProvider::Codex,
        );
        $notionIntegration = ProjectIntegration::query()
            ->forOrganization($organizationId)
            ->forProject($project->id)
            ->where('provider', IntegrationProvider::Notion->value)
            ->first();
        $progress = ProjectSetupProgress::query()
            ->where('project_id', $project->id)
            ->first();

        $issues = [
            ...$this->technologyStackIssues($configuration),
            ...$this->repositoryIssues($configuration),
            ...$this->notionIntegrationIssues($notionCredential, $notionIntegration),
            ...$this->codexIntegrationIssues($configuration, $codexCredential),
            ...$this->validationCommandIssues($configuration),
            ...$this->policyIssues($configuration),
            ...$this->requiredDocumentIssues($project, $configuration),
            ...$this->reviewIssues($progress),
        ];

        return ProjectCompletenessResult::forProject(
            projectId: $project->id,
            issues: $issues,
        );
    }

    /**
     * Read only safe credential metadata required by preflight evaluation.
     */
    private function credentialMetadata(
        int $organizationId,
        int $projectId,
        IntegrationProvider $provider,
    ): ?ProviderCredential {
        return ProviderCredential::query()
            ->select([
                'id',
                'organization_id',
                'project_id',
                'provider',
                'version',
                'last_connection_status',
                'last_connection_failure_code',
                'verified_credential_version',
                'last_tested_at',
                'last_connected_at',
                'created_at',
                'updated_at',
                'rotated_at',
            ])
            ->forOrganization($organizationId)
            ->forProject($projectId)
            ->where('provider', $provider->value)
            ->first();
    }

    /**
     * Evaluate technology stack shape and required primary language.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function technologyStackIssues(ProjectConfiguration $configuration): array
    {
        $issues = [];

        foreach (['languages', 'frameworks', 'databases', 'infrastructure', 'package_managers', 'runtimes'] as $section) {
            $values = $configuration->technology_stack[$section] ?? null;

            if (! is_array($values) || ! array_is_list($values)) {
                $issues[] = $this->issue(
                    "technology_stack.{$section}",
                    ProjectSetupStep::Details,
                    sprintf('Technology stack field "%s" is missing or malformed.', $section),
                    'Open Technology stack, correct the listed values, and save the step again.',
                );
            }
        }

        $languages = $configuration->technology_stack['languages'] ?? null;

        if (is_array($languages) && array_is_list($languages) && ! $this->containsNonEmptyString($languages)) {
            $issues[] = $this->issue(
                'technology_stack.languages',
                ProjectSetupStep::Details,
                'At least one project language is required.',
                'Open Technology stack, add the primary project language, and save the step.',
            );
        }

        return $this->uniqueIssues($issues);
    }

    /**
     * Evaluate repository metadata and protected integration branch policy.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function repositoryIssues(ProjectConfiguration $configuration): array
    {
        $issues = [];

        if ($configuration->repository_provider === null) {
            $issues[] = $this->issue('repository.provider', ProjectSetupStep::Repository, 'The repository provider is missing.', 'Open Repository, select GitHub, and save the step.');
        }

        if (! is_string($configuration->repository_url) || ! GitHubRepositoryUrl::isValid($configuration->repository_url)) {
            $issues[] = $this->issue('repository.url', ProjectSetupStep::Repository, 'A valid credential-free GitHub HTTPS repository URL is required.', 'Open Repository, enter the repository root URL, and save the step.');
        }

        if (! is_string($configuration->default_branch) || ! GitBranchName::isValid($configuration->default_branch)) {
            $issues[] = $this->issue('repository.default_branch', ProjectSetupStep::Repository, 'A valid default branch is required.', 'Open Repository, enter the default branch, and save the step.');
        }

        if (! GitBranchName::isValid($configuration->integration_branch)) {
            $issues[] = $this->issue('repository.integration_branch', ProjectSetupStep::Repository, 'A valid integration branch is required.', 'Open Repository, enter a valid integration branch such as develop, and save the step.');
        } elseif ($configuration->integration_branch === 'main') {
            $issues[] = $this->issue('repository.integration_branch', ProjectSetupStep::Repository, 'The automated integration branch cannot be main.', 'Open Repository, change the integration branch to develop or another non-main branch, and save the step.');
        }

        return $issues;
    }

    /**
     * Evaluate credential presence and safe Notion connection metadata.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function notionIntegrationIssues(
        ?ProviderCredential $credential,
        ?ProjectIntegration $integration,
    ): array {
        $issues = [];

        if ($credential === null) {
            $issues[] = $this->issue('integrations.notion.credential', ProjectSetupStep::Integrations, 'A Notion integration credential has not been configured.', 'Open Integrations, enter a least-privilege Notion token, and run the connection test.');
        }

        if ($integration === null) {
            $issues[] = $this->issue('integrations.notion.connection', ProjectSetupStep::Integrations, 'The Notion connection has not been tested.', 'Open Integrations, provide the project database, and run the Notion connection test.');

            return $issues;
        }

        if ($integration->connection_status !== NotionConnectionStatus::Connected) {
            $failureCode = $integration->last_failure_code?->value;
            $issues[] = $this->issue(
                'integrations.notion.connection',
                ProjectSetupStep::Integrations,
                $failureCode === null
                    ? 'The latest Notion connection test did not succeed.'
                    : sprintf('The latest Notion connection test failed with code "%s".', $failureCode),
                'Open Integrations, correct the credential or database access, and run the connection test again.',
            );
        } elseif (
            $credential !== null
            && $credential->updated_at !== null
            && $integration->last_tested_at !== null
            && $credential->updated_at->greaterThan($integration->last_tested_at)
        ) {
            $issues[] = $this->issue('integrations.notion.connection', ProjectSetupStep::Integrations, 'The Notion credential changed after the latest successful connection test.', 'Open Integrations and run the Notion connection test again using the current credential.');
        }

        if (! $this->isNonEmptyString($integration->workspace_id)) {
            $issues[] = $this->issue('integrations.notion.workspace', ProjectSetupStep::Integrations, 'The verified Notion workspace is missing.', 'Open Integrations and rerun the connection test so workspace metadata can be verified.');
        }

        if (! $this->isNonEmptyString($integration->database_id)) {
            $issues[] = $this->issue('integrations.notion.database', ProjectSetupStep::Integrations, 'The verified Notion project database is missing.', 'Open Integrations, enter the project database, and rerun the connection test.');
        }

        return $this->uniqueIssues($issues);
    }

    /**
     * Require a current verified Codex credential only when Codex is enabled.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function codexIntegrationIssues(
        ProjectConfiguration $configuration,
        ?ProviderCredential $credential,
    ): array {
        $codexPayload = $configuration->provider_policy['codex']
            ?? CodexProviderPolicy::defaults();

        if (! is_array($codexPayload)) {
            return [$this->issue(
                'policy.provider.codex',
                ProjectSetupStep::Policies,
                'The persisted Codex provider policy is malformed.',
                'Open Integrations, review the Codex provider policy, and save it again.',
            )];
        }

        try {
            $policy = CodexProviderPolicy::fromArray($codexPayload);
        } catch (InvalidArgumentException) {
            return [$this->issue(
                'policy.provider.codex',
                ProjectSetupStep::Policies,
                'The persisted Codex provider policy is invalid.',
                'Open Integrations, review the Codex security policy, and save it again.',
            )];
        }

        if (! $policy->enabled) {
            return [];
        }

        if ($credential === null) {
            return [$this->issue(
                'integrations.codex.credential',
                ProjectSetupStep::Integrations,
                'Codex is enabled but no project-scoped credential is configured.',
                'Open Integrations, enter the Codex credential twice, and run server-side preflight.',
            )];
        }

        if (
            $credential->last_connection_status !== 'connected'
            || $credential->verified_credential_version !== $credential->version
            || $credential->last_connected_at === null
        ) {
            return [$this->issue(
                'integrations.codex.connection',
                ProjectSetupStep::Integrations,
                'Codex is enabled but the current credential has not passed preflight.',
                'Open Integrations and run Codex preflight using the currently stored credential.',
            )];
        }

        return [];
    }

    /**
     * Validate every configured deterministic project command.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function validationCommandIssues(ProjectConfiguration $configuration): array
    {
        $issues = [];
        $commands = [
            'build' => $configuration->build_command,
            'test' => $configuration->test_command,
            'lint' => $configuration->lint_command,
            'static_analysis' => $configuration->static_analysis_command,
            'security' => $configuration->security_command,
        ];

        foreach ($commands as $name => $command) {
            $label = str_replace('_', ' ', $name);

            if (! is_string($command) || trim($command) === '') {
                $issues[] = $this->issue("validation_commands.{$name}", ProjectSetupStep::Commands, sprintf('The %s validation command is missing.', $label), sprintf('Open Validation commands, enter the %s command, and save the step.', $label));

                continue;
            }

            try {
                ValidationCommand::from($command);
            } catch (InvalidValidationCommand) {
                $issues[] = $this->issue("validation_commands.{$name}", ProjectSetupStep::Commands, sprintf('The %s validation command is invalid.', $label), sprintf('Open Validation commands, correct the %s command, and save the step.', $label));
            }
        }

        return $issues;
    }

    /**
     * Evaluate execution policy and required-document configuration.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function policyIssues(ProjectConfiguration $configuration): array
    {
        $issues = [];

        if (! $this->containsNonEmptyString($configuration->required_documents)) {
            $issues[] = $this->issue('required_documents', ProjectSetupStep::Policies, 'At least one required project document must be configured.', 'Open Policies, list the document classes required before project start, and save the step.');
        }

        $allowedProviders = $configuration->provider_policy['allowed_provider_ids'] ?? null;
        $fallbackOrder = $configuration->provider_policy['fallback_order'] ?? null;
        $canValidate = true;

        if (! is_array($allowedProviders) || ! $this->containsNonEmptyString($allowedProviders)) {
            $canValidate = false;
            $issues[] = $this->issue('policy.provider.allowed_provider_ids', ProjectSetupStep::Policies, 'At least one execution provider must be allowed.', 'Open Policies, add an allowed provider such as simulation, and save the step.');
        }

        if (! is_array($fallbackOrder) || ! $this->containsNonEmptyString($fallbackOrder)) {
            $canValidate = false;
            $issues[] = $this->issue('policy.provider.fallback_order', ProjectSetupStep::Policies, 'The provider fallback order is missing.', 'Open Policies, define the provider fallback order, and save the step.');
        }

        if ($canValidate) {
            try {
                $providerPolicy = ProviderPolicy::fromArray($configuration->provider_policy);
                $providerPolicy->codex->assertWithinProjectPolicy(
                    $configuration->default_reasoning,
                    $configuration->budget_limit_minor,
                    $configuration->automatic_retry_limit,
                );

                ProjectPolicyConfiguration::fromValidatedPayload([
                    'default_reasoning' => $configuration->default_reasoning,
                    'provider_policy' => $configuration->provider_policy,
                    'budget_limit_minor' => $configuration->budget_limit_minor,
                    'budget_currency' => $configuration->budget_currency,
                    'automatic_retry_limit' => $configuration->automatic_retry_limit,
                    'autonomy_level' => $configuration->autonomy_level,
                    'approval_policy' => $configuration->approval_policy,
                    'notification_policy' => $configuration->notification_policy,
                ]);
            } catch (InvalidArgumentException) {
                $issues[] = $this->issue('policy.configuration', ProjectSetupStep::Policies, 'The persisted execution policy is invalid.', 'Open Policies and Integrations, review every provider policy value, and save the configuration again.');
            }
        }

        return $this->uniqueIssues($issues);
    }

    /**
     * Block preflight until every configured document class has an approved version.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function requiredDocumentIssues(Project $project, ProjectConfiguration $configuration): array
    {
        $requiredClasses = array_values(array_filter(
            $configuration->required_documents,
            fn (mixed $documentClass): bool => $this->isNonEmptyString($documentClass),
        ));

        if ($requiredClasses === []) {
            return [];
        }

        $approvedClasses = Document::query()
            ->where('project_id', $project->id)
            ->whereIn('document_class', $requiredClasses)
            ->whereHas('versions', fn ($query) => $query
                ->where('status', DocumentStatus::Approved->value)
                ->whereNotNull('analyzer_name')
                ->whereNotNull('analyzer_version')
                ->whereNotNull('analysis_seed')
                ->whereNotNull('analysis_completed_at')
                ->whereNotNull('analysis_summary')
                ->whereNotNull('analysis_conflicts')
                ->whereNotNull('analysis_gaps')
                ->whereNotNull('analysis_flags'))
            ->pluck('document_class')
            ->filter()
            ->all();

        return array_map(
            fn (string $documentClass): ProjectCompletenessIssue => $this->issue(
                "required_documents.{$documentClass}",
                ProjectSetupStep::Policies,
                sprintf('The required "%s" document class has no approved version.', $documentClass),
                'Upload the required document, complete processing, and explicitly approve its version before project preflight.',
            ),
            array_values(array_diff($requiredClasses, $approvedClasses)),
        );
    }

    /**
     * Require final human confirmation after configuration changes.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function reviewIssues(?ProjectSetupProgress $progress): array
    {
        if ($progress?->isComplete() === true) {
            return [];
        }

        return [$this->issue('setup.review', ProjectSetupStep::Review, 'The persisted project configuration has not been finally confirmed.', 'Open Review, verify the persisted configuration, and submit the confirmation.')];
    }

    /**
     * Create one normalized completeness issue.
     */
    private function issue(string $key, ProjectSetupStep $step, string $message, string $remediation): ProjectCompletenessIssue
    {
        return new ProjectCompletenessIssue(
            key: $key,
            step: $step,
            message: $message,
            remediation: $remediation,
        );
    }

    /**
     * Determine whether a list contains at least one non-empty string.
     *
     * @param  array<mixed>  $values
     */
    private function containsNonEmptyString(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->isNonEmptyString($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether one mixed value is a non-empty string.
     */
    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * Remove duplicate issue keys while preserving first-occurrence order.
     *
     * @param  list<ProjectCompletenessIssue>  $issues
     * @return list<ProjectCompletenessIssue>
     */
    private function uniqueIssues(array $issues): array
    {
        $unique = [];

        foreach ($issues as $issue) {
            $unique[$issue->key] ??= $issue;
        }

        return array_values($unique);
    }

    /**
     * Reject invalid internal identifiers before database access.
     */
    private function assertPositiveIdentifier(int $identifier, string $name): void
    {
        if ($identifier < 1) {
            throw new InvalidArgumentException(
                "The {$name} identifier must be positive.",
            );
        }
    }
}
