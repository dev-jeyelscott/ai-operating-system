<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Domain\Integrations\IntegrationProvider;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectIntegration;
use App\Models\ProviderCredential;

/**
 * Builds the non-secret read model used by project configuration screens.
 *
 * This query performs no writes and makes no external provider calls.
 */
final readonly class GetProjectConfigurationOverview
{
    /**
     * Inject the existing deterministic completeness evaluator.
     */
    public function __construct(
        private EvaluateProjectCompleteness $evaluateProjectCompleteness,
    ) {}

    /**
     * Return safe configuration, integration, and validation metadata.
     *
     * @return array{
     *     configuration: array<string, mixed>|null,
     *     integration: array<string, mixed>,
     *     validation: array{
     *         complete: bool,
     *         missingConfiguration: list<array{
     *             key: string,
     *             step: string,
     *             message: string,
     *             remediation: string
     *         }>
     *     }
     * }
     */
    public function handle(
        int $organizationId,
        int $projectId,
    ): array {
        /*
         * Resolve the project through the organization boundary before reading
         * project-owned configuration and integration records.
         */
        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();

        $configuration = ProjectConfiguration::query()
            ->where('project_id', $project->id)
            ->first();

        $integration = ProjectIntegration::query()
            ->forOrganization($organizationId)
            ->forProject($project->id)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->first();

        /*
         * Select only the columns required by toSafeMetadata().
         *
         * secret_ciphertext is intentionally excluded at query time, providing
         * defense in depth in addition to the model's hidden attribute.
         */
        $credential = ProviderCredential::query()
            ->select([
                'id',
                'organization_id',
                'project_id',
                'provider',
                'version',
                'created_at',
                'rotated_at',
            ])
            ->forOrganization($organizationId)
            ->forProject($project->id)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->first();

        $completeness = $this->evaluateProjectCompleteness->handle(
            organizationId: $organizationId,
            projectId: $project->id,
        );

        return [
            'configuration' => $this->serializeConfiguration($configuration),
            'integration' => $this->serializeIntegration(
                integration: $integration,
                credential: $credential,
            ),
            'validation' => [
                'complete' => $completeness->isComplete(),
                'missingConfiguration' => array_map(
                    static fn ($issue): array => $issue->toArray(),
                    $completeness->issues,
                ),
            ],
        ];
    }

    /**
     * Serialize persisted configuration into a browser-safe read model.
     *
     * @return array<string, mixed>|null
     */
    private function serializeConfiguration(
        ?ProjectConfiguration $configuration,
    ): ?array {
        if ($configuration === null) {
            return null;
        }

        return [
            'schemaVersion' => $configuration->schema_version,
            'revision' => $configuration->revision,
            'technologyStack' => $configuration->technology_stack,
            'repository' => [
                'provider' => $configuration->repository_provider?->value,
                'url' => $configuration->repository_url,
                'defaultBranch' => $configuration->default_branch,
                'integrationBranch' => $configuration->integration_branch,
            ],
            'commands' => [
                'build' => $configuration->build_command,
                'test' => $configuration->test_command,
                'lint' => $configuration->lint_command,
                'staticAnalysis' => $configuration->static_analysis_command,
                'security' => $configuration->security_command,
            ],
            'requiredDocuments' => $configuration->required_documents,
            'policy' => [
                'defaultReasoning' => $configuration
                    ->default_reasoning
                    ->value,
                'provider' => $configuration->provider_policy,
                'budgetLimitMinor' => $configuration->budget_limit_minor,
                'budgetCurrency' => $configuration->budget_currency,
                'automaticRetryLimit' => $configuration
                    ->automatic_retry_limit,
                'autonomyLevel' => $configuration->autonomy_level->value,
                'approval' => $configuration->approval_policy,
                'notification' => $configuration->notification_policy,
            ],
        ];
    }

    /**
     * Serialize safe Notion connection and credential metadata.
     *
     * @return array{
     *     provider: string,
     *     status: string|null,
     *     workspaceId: string|null,
     *     workspaceName: string|null,
     *     databaseId: string|null,
     *     databaseName: string|null,
     *     lastFailureCode: string|null,
     *     lastTestedAt: string|null,
     *     lastConnectedAt: string|null,
     *     credential: array{
     *         configured: bool,
     *         version: int|null,
     *         createdAt: string|null,
     *         rotatedAt: string|null
     *     }
     * }
     */
    private function serializeIntegration(
        ?ProjectIntegration $integration,
        ?ProviderCredential $credential,
    ): array {
        $connectionMetadata = $integration?->toSafeMetadata();
        $credentialMetadata = $credential?->toSafeMetadata();

        return [
            'provider' => IntegrationProvider::Notion->value,
            'status' => $this->nullableString(
                $connectionMetadata['status'] ?? null,
            ),
            'workspaceId' => $this->nullableString(
                $connectionMetadata['workspace_id'] ?? null,
            ),
            'workspaceName' => $this->nullableString(
                $connectionMetadata['workspace_name'] ?? null,
            ),
            'databaseId' => $this->nullableString(
                $connectionMetadata['database_id'] ?? null,
            ),
            'databaseName' => $this->nullableString(
                $connectionMetadata['database_name'] ?? null,
            ),
            'lastFailureCode' => $this->nullableString(
                $connectionMetadata['last_failure_code'] ?? null,
            ),
            'lastTestedAt' => $this->nullableString(
                $connectionMetadata['last_tested_at'] ?? null,
            ),
            'lastConnectedAt' => $this->nullableString(
                $connectionMetadata['last_connected_at'] ?? null,
            ),
            'credential' => [
                'configured' => $credentialMetadata !== null,
                'version' => $credentialMetadata['version'] ?? null,
                'createdAt' => $credentialMetadata['created_at'] ?? null,
                'rotatedAt' => $credentialMetadata['rotated_at'] ?? null,
            ],
        ];
    }

    /**
     * Return a string value or null for a mixed safe-metadata entry.
     */
    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
