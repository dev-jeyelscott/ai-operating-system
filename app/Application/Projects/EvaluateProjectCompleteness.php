<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Domain\Documents\DocumentStatus;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
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
    public function handle(
        int $organizationId,
        int $projectId,
    ): ProjectCompletenessResult {
        $this->assertPositiveIdentifier(
            $organizationId,
            'organization',
        );

        $this->assertPositiveIdentifier(
            $projectId,
            'project',
        );

        /*
         * Resolve the project through the explicit tenant boundary before
         * querying project-owned configuration.
         */
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
                issues: [
                    $this->issue(
                        key: 'configuration.record',
                        step: ProjectSetupStep::Details,
                        message: 'The project configuration record is missing.',
                        remediation: 'Open project setup and save the Technology stack step to initialize the configuration.',
                    ),
                ],
            );
        }

        /*
         * Never guess how an unsupported configuration schema should be read.
         */
        if (! $configuration->usesCurrentSchema()) {
            return ProjectCompletenessResult::forProject(
                projectId: $project->id,
                issues: [
                    $this->issue(
                        key: 'configuration.schema_version',
                        step: ProjectSetupStep::Review,
                        message: sprintf(
                            'Project configuration schema version %d is unsupported.',
                            $configuration->schema_version,
                        ),
                        remediation: 'Migrate or upcast the project configuration before running project preflight.',
                    ),
                ],
            );
        }

        /*
         * Select credential metadata only. Ciphertext is intentionally excluded
         * because completeness does not require decryption.
         */
        $notionCredential = ProviderCredential::query()
            ->select([
                'id',
                'organization_id',
                'project_id',
                'provider',
                'version',
                'created_at',
                'updated_at',
                'rotated_at',
            ])
            ->forOrganization($organizationId)
            ->forProject($project->id)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->first();

        $notionIntegration = ProjectIntegration::query()
            ->forOrganization($organizationId)
            ->forProject($project->id)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->first();

        $progress = ProjectSetupProgress::query()
            ->where('project_id', $project->id)
            ->first();

        $issues = [
            ...$this->technologyStackIssues($configuration),
            ...$this->repositoryIssues($configuration),
            ...$this->notionIntegrationIssues(
                credential: $notionCredential,
                integration: $notionIntegration,
            ),
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
     * Evaluate the technology-stack section.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function technologyStackIssues(
        ProjectConfiguration $configuration,
    ): array {
        $technologyStack = $configuration->technology_stack;
        $issues = [];

        foreach ([
            'languages',
            'frameworks',
            'databases',
            'infrastructure',
            'package_managers',
            'runtimes',
        ] as $section) {
            $values = $technologyStack[$section] ?? null;

            if (! is_array($values) || ! array_is_list($values)) {
                $issues[] = $this->issue(
                    key: "technology_stack.{$section}",
                    step: ProjectSetupStep::Details,
                    message: sprintf(
                        'Technology stack field "%s" is missing or malformed.',
                        $section,
                    ),
                    remediation: 'Open Technology stack, correct the listed values, and save the step again.',
                );
            }
        }

        $languages = $technologyStack['languages'] ?? null;

        if (
            is_array($languages)
            && array_is_list($languages)
            && ! $this->containsNonEmptyString($languages)
        ) {
            $issues[] = $this->issue(
                key: 'technology_stack.languages',
                step: ProjectSetupStep::Details,
                message: 'At least one project language is required.',
                remediation: 'Open Technology stack, add the primary project language, and save the step.',
            );
        }

        return $this->uniqueIssues($issues);
    }

    /**
     * Evaluate repository metadata and integration-branch policy.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function repositoryIssues(
        ProjectConfiguration $configuration,
    ): array {
        $issues = [];

        if ($configuration->repository_provider === null) {
            $issues[] = $this->issue(
                key: 'repository.provider',
                step: ProjectSetupStep::Repository,
                message: 'The repository provider is missing.',
                remediation: 'Open Repository, select GitHub, and save the step.',
            );
        }

        if (
            ! is_string($configuration->repository_url)
            || ! GitHubRepositoryUrl::isValid(
                $configuration->repository_url,
            )
        ) {
            $issues[] = $this->issue(
                key: 'repository.url',
                step: ProjectSetupStep::Repository,
                message: 'A valid credential-free GitHub HTTPS repository URL is required.',
                remediation: 'Open Repository, enter the repository root URL, and save the step.',
            );
        }

        if (
            ! is_string($configuration->default_branch)
            || ! GitBranchName::isValid(
                $configuration->default_branch,
            )
        ) {
            $issues[] = $this->issue(
                key: 'repository.default_branch',
                step: ProjectSetupStep::Repository,
                message: 'A valid default branch is required.',
                remediation: 'Open Repository, enter the default branch, and save the step.',
            );
        }

        if (
            ! GitBranchName::isValid(
                $configuration->integration_branch,
            )
        ) {
            $issues[] = $this->issue(
                key: 'repository.integration_branch',
                step: ProjectSetupStep::Repository,
                message: 'A valid integration branch is required.',
                remediation: 'Open Repository, enter a valid integration branch such as develop, and save the step.',
            );
        } elseif ($configuration->integration_branch === 'main') {
            $issues[] = $this->issue(
                key: 'repository.integration_branch',
                step: ProjectSetupStep::Repository,
                message: 'The automated integration branch cannot be main.',
                remediation: 'Open Repository, change the integration branch to develop or another non-main branch, and save the step.',
            );
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
            $issues[] = $this->issue(
                key: 'integrations.notion.credential',
                step: ProjectSetupStep::Integrations,
                message: 'A Notion integration credential has not been configured.',
                remediation: 'Open Integrations, enter a least-privilege Notion token, and run the connection test.',
            );
        }

        if ($integration === null) {
            $issues[] = $this->issue(
                key: 'integrations.notion.connection',
                step: ProjectSetupStep::Integrations,
                message: 'The Notion connection has not been tested.',
                remediation: 'Open Integrations, provide the project database, and run the Notion connection test.',
            );

            return $issues;
        }

        if (
            $integration->connection_status
            !== NotionConnectionStatus::Connected
        ) {
            $failureCode = $integration
                ->last_failure_code
                ?->value;

            $issues[] = $this->issue(
                key: 'integrations.notion.connection',
                step: ProjectSetupStep::Integrations,
                message: $failureCode === null
                    ? 'The latest Notion connection test did not succeed.'
                    : sprintf(
                        'The latest Notion connection test failed with code "%s".',
                        $failureCode,
                    ),
                remediation: 'Open Integrations, correct the credential or database access, and run the connection test again.',
            );
        } elseif (
            $credential !== null
            && $credential->updated_at !== null
            && $credential->updated_at->greaterThan(
                $integration->last_tested_at,
            )
        ) {
            /*
             * A successful test for an older credential does not validate the
             * currently stored credential version.
             */
            $issues[] = $this->issue(
                key: 'integrations.notion.connection',
                step: ProjectSetupStep::Integrations,
                message: 'The Notion credential changed after the latest successful connection test.',
                remediation: 'Open Integrations and run the Notion connection test again using the current credential.',
            );
        }

        if (! $this->isNonEmptyString($integration->workspace_id)) {
            $issues[] = $this->issue(
                key: 'integrations.notion.workspace',
                step: ProjectSetupStep::Integrations,
                message: 'The verified Notion workspace is missing.',
                remediation: 'Open Integrations and rerun the connection test so workspace metadata can be verified.',
            );
        }

        if (! $this->isNonEmptyString($integration->database_id)) {
            $issues[] = $this->issue(
                key: 'integrations.notion.database',
                step: ProjectSetupStep::Integrations,
                message: 'The verified Notion project database is missing.',
                remediation: 'Open Integrations, enter the project database, and rerun the connection test.',
            );
        }

        return $this->uniqueIssues($issues);
    }

    /**
     * Evaluate all required validation commands using the existing domain guard.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function validationCommandIssues(
        ProjectConfiguration $configuration,
    ): array {
        $issues = [];

        $commands = [
            'build' => $configuration->build_command,
            'test' => $configuration->test_command,
            'lint' => $configuration->lint_command,
            'static_analysis' => $configuration
                ->static_analysis_command,
            'security' => $configuration->security_command,
        ];

        foreach ($commands as $name => $command) {
            $label = str_replace('_', ' ', $name);

            if (! is_string($command) || trim($command) === '') {
                $issues[] = $this->issue(
                    key: "validation_commands.{$name}",
                    step: ProjectSetupStep::Commands,
                    message: sprintf(
                        'The %s validation command is missing.',
                        $label,
                    ),
                    remediation: sprintf(
                        'Open Validation commands, enter the %s command, and save the step.',
                        $label,
                    ),
                );

                continue;
            }

            try {
                ValidationCommand::from($command);
            } catch (InvalidValidationCommand) {
                $issues[] = $this->issue(
                    key: "validation_commands.{$name}",
                    step: ProjectSetupStep::Commands,
                    message: sprintf(
                        'The %s validation command is invalid.',
                        $label,
                    ),
                    remediation: sprintf(
                        'Open Validation commands, correct the %s command, and save the step.',
                        $label,
                    ),
                );
            }
        }

        return $issues;
    }

    /**
     * Evaluate required documents and execution policy configuration.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function policyIssues(
        ProjectConfiguration $configuration,
    ): array {
        $issues = [];

        if (
            ! $this->containsNonEmptyString(
                $configuration->required_documents,
            )
        ) {
            $issues[] = $this->issue(
                key: 'required_documents',
                step: ProjectSetupStep::Policies,
                message: 'At least one required project document must be configured.',
                remediation: 'Open Policies, list the document classes required before project start, and save the step.',
            );
        }

        $allowedProviders = $configuration
            ->provider_policy['allowed_provider_ids']
            ?? null;

        $fallbackOrder = $configuration
            ->provider_policy['fallback_order']
            ?? null;

        $providerPolicyCanBeValidated = true;

        if (
            ! is_array($allowedProviders)
            || ! $this->containsNonEmptyString($allowedProviders)
        ) {
            $providerPolicyCanBeValidated = false;

            $issues[] = $this->issue(
                key: 'policy.provider.allowed_provider_ids',
                step: ProjectSetupStep::Policies,
                message: 'At least one execution provider must be allowed.',
                remediation: 'Open Policies, add an allowed provider such as simulation, and save the step.',
            );
        }

        if (
            ! is_array($fallbackOrder)
            || ! $this->containsNonEmptyString($fallbackOrder)
        ) {
            $providerPolicyCanBeValidated = false;

            $issues[] = $this->issue(
                key: 'policy.provider.fallback_order',
                step: ProjectSetupStep::Policies,
                message: 'The provider fallback order is missing.',
                remediation: 'Open Policies, define the provider fallback order, and save the step.',
            );
        }

        if ($providerPolicyCanBeValidated) {
            try {
                ProviderPolicy::fromArray(
                    $configuration->provider_policy,
                );
            } catch (InvalidArgumentException) {
                $issues[] = $this->issue(
                    key: 'policy.provider',
                    step: ProjectSetupStep::Policies,
                    message: 'The provider allowlist and fallback order are inconsistent.',
                    remediation: 'Open Policies, ensure every fallback provider is allowed and each provider appears only once, then save the step.',
                );
            }

            try {
                ProjectPolicyConfiguration::fromValidatedPayload([
                    'default_reasoning' => $configuration
                        ->default_reasoning,
                    'provider_policy' => $configuration
                        ->provider_policy,
                    'budget_limit_minor' => $configuration
                        ->budget_limit_minor,
                    'budget_currency' => $configuration
                        ->budget_currency,
                    'automatic_retry_limit' => $configuration
                        ->automatic_retry_limit,
                    'autonomy_level' => $configuration
                        ->autonomy_level,
                    'approval_policy' => $configuration
                        ->approval_policy,
                    'notification_policy' => $configuration
                        ->notification_policy,
                ]);
            } catch (InvalidArgumentException) {
                $issues[] = $this->issue(
                    key: 'policy.configuration',
                    step: ProjectSetupStep::Policies,
                    message: 'The persisted execution policy is invalid.',
                    remediation: 'Open Policies, review every policy value, and save the step again.',
                );
            }
        }

        return $this->uniqueIssues($issues);
    }

    /**
     * Block preflight until every configured document class has an approved version.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function requiredDocumentIssues(
        Project $project,
        ProjectConfiguration $configuration,
    ): array {
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
            ->whereHas(
                'versions',
                fn ($query) => $query->where(
                    'status',
                    DocumentStatus::Approved->value,
                ),
            )
            ->pluck('document_class')
            ->filter()
            ->all();

        return array_map(
            fn (string $documentClass): ProjectCompletenessIssue => $this->issue(
                key: "required_documents.{$documentClass}",
                step: ProjectSetupStep::Policies,
                message: sprintf(
                    'The required "%s" document class has no approved version.',
                    $documentClass,
                ),
                remediation: 'Upload the required document, complete processing, and explicitly approve its version before project preflight.',
            ),
            array_values(array_diff($requiredClasses, $approvedClasses)),
        );
    }

    /**
     * Require final confirmation after persisted values are complete.
     *
     * Wizard progress is not used to validate individual values. It is used
     * only for the explicit final human confirmation represented by completed_at.
     *
     * @return list<ProjectCompletenessIssue>
     */
    private function reviewIssues(
        ?ProjectSetupProgress $progress,
    ): array {
        if ($progress?->isComplete() === true) {
            return [];
        }

        return [
            $this->issue(
                key: 'setup.review',
                step: ProjectSetupStep::Review,
                message: 'The persisted project configuration has not been finally confirmed.',
                remediation: 'Open Review, verify the persisted configuration, and submit the confirmation.',
            ),
        ];
    }

    /**
     * Create one normalized completeness issue.
     */
    private function issue(
        string $key,
        ProjectSetupStep $step,
        string $message,
        string $remediation,
    ): ProjectCompletenessIssue {
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
     * Determine whether one value is a non-empty string after trimming.
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
     * Reject invalid internal identifiers before querying persistence.
     */
    private function assertPositiveIdentifier(
        int $identifier,
        string $name,
    ): void {
        if ($identifier < 1) {
            throw new InvalidArgumentException(
                "The {$name} identifier must be positive.",
            );
        }
    }
}
