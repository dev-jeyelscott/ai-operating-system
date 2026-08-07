<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Projects\Configuration\CodexProviderPolicy;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectIntegration;
use App\Models\ProviderCredential;

/**
 * Builds the non-secret read model used by project configuration screens.
 */
final readonly class GetProjectConfigurationOverview
{
    /**
     * Inject the deterministic completeness evaluator.
     */
    public function __construct(
        private EvaluateProjectCompleteness $evaluateProjectCompleteness,
    ) {}

    /**
     * Return safe configuration, integration, and validation metadata.
     *
     * @return array<string, mixed>
     */
    public function handle(int $organizationId, int $projectId): array
    {
        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();
        $configuration = ProjectConfiguration::query()
            ->where('project_id', $project->id)
            ->first();

        $notionIntegration = ProjectIntegration::query()
            ->forOrganization($organizationId)
            ->forProject($project->id)
            ->where('provider', IntegrationProvider::Notion->value)
            ->first();
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

        $completeness = $this->evaluateProjectCompleteness->handle(
            organizationId: $organizationId,
            projectId: $project->id,
        );

        return [
            'configuration' => $this->serializeConfiguration($configuration),
            'integration' => $this->serializeNotionIntegration(
                $notionIntegration,
                $notionCredential,
            ),
            'codex' => $this->serializeCodex(
                $configuration,
                $codexCredential,
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
     * Select credential metadata without selecting ciphertext.
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
                'last_provider_request_id',
                'verified_credential_version',
                'last_tested_at',
                'last_connected_at',
                'created_at',
                'rotated_at',
            ])
            ->forOrganization($organizationId)
            ->forProject($projectId)
            ->where('provider', $provider->value)
            ->first();
    }

    /**
     * Serialize persisted configuration into a browser-safe read model.
     */
    private function serializeConfiguration(?ProjectConfiguration $configuration): ?array
    {
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
                'defaultReasoning' => $configuration->default_reasoning->value,
                'provider' => $configuration->provider_policy,
                'budgetLimitMinor' => $configuration->budget_limit_minor,
                'budgetCurrency' => $configuration->budget_currency,
                'automaticRetryLimit' => $configuration->automatic_retry_limit,
                'autonomyLevel' => $configuration->autonomy_level->value,
                'approval' => $configuration->approval_policy,
                'notification' => $configuration->notification_policy,
            ],
        ];
    }

    /**
     * Serialize safe Notion metadata using the established read model.
     */
    private function serializeNotionIntegration(
        ?ProjectIntegration $integration,
        ?ProviderCredential $credential,
    ): array {
        $connectionMetadata = $integration?->toSafeMetadata();
        $credentialMetadata = $credential?->toSafeMetadata();

        return [
            'provider' => IntegrationProvider::Notion->value,
            'status' => $this->nullableString($connectionMetadata['status'] ?? null),
            'workspaceId' => $this->nullableString($connectionMetadata['workspace_id'] ?? null),
            'workspaceName' => $this->nullableString($connectionMetadata['workspace_name'] ?? null),
            'databaseId' => $this->nullableString($connectionMetadata['database_id'] ?? null),
            'databaseName' => $this->nullableString($connectionMetadata['database_name'] ?? null),
            'lastFailureCode' => $this->nullableString($connectionMetadata['last_failure_code'] ?? null),
            'lastTestedAt' => $this->nullableString($connectionMetadata['last_tested_at'] ?? null),
            'lastConnectedAt' => $this->nullableString($connectionMetadata['last_connected_at'] ?? null),
            'credential' => [
                'configured' => $credentialMetadata !== null,
                'version' => $credentialMetadata['version'] ?? null,
                'createdAt' => $credentialMetadata['created_at'] ?? null,
                'rotatedAt' => $credentialMetadata['rotated_at'] ?? null,
            ],
        ];
    }

    /**
     * Serialize only safe Codex policy/credential/preflight metadata.
     */
    private function serializeCodex(
        ?ProjectConfiguration $configuration,
        ?ProviderCredential $credential,
    ): array {
        $policy = $configuration?->provider_policy['codex'] ?? null;

        if (! is_array($policy)) {
            $policy = CodexProviderPolicy::defaults();
        }

        $credentialMetadata = $credential?->toSafeMetadata();

        return [
            'provider' => IntegrationProvider::Codex->value,
            'scope' => 'project',
            'policy' => $policy,
            'fallbackEnabled' => $configuration !== null
                && in_array(
                    IntegrationProvider::Codex->value,
                    $configuration->provider_policy['fallback_order'] ?? [],
                    true,
                ),
            'credential' => [
                'configured' => $credentialMetadata !== null,
                'version' => $credentialMetadata['version'] ?? null,
                'createdAt' => $credentialMetadata['created_at'] ?? null,
                'rotatedAt' => $credentialMetadata['rotated_at'] ?? null,
            ],
            'connection' => [
                'status' => $credentialMetadata['connection_status'] ?? null,
                'failureCode' => $credentialMetadata['connection_failure_code'] ?? null,
                'providerRequestId' => $credentialMetadata['provider_request_id'] ?? null,
                'verifiedCredentialVersion' => $credentialMetadata['verified_credential_version'] ?? null,
                'lastTestedAt' => $credentialMetadata['last_tested_at'] ?? null,
                'lastConnectedAt' => $credentialMetadata['last_connected_at'] ?? null,
            ],
        ];
    }

    /**
     * Return a string value or null for mixed safe metadata.
     */
    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
