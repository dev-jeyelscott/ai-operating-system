<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Projects\Data\StartProjectPreflightResult;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Projects\Configuration\ProjectCompletenessIssue;
use App\Domain\Projects\ProjectStatus;
use App\Models\DocumentVersion;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use App\Models\ProjectIntegration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;

/**
 * Builds the deterministic, read-only StartProject preflight report.
 *
 * This query never creates a snapshot, workflow, execution, approval, or
 * provider request. AIOS-063 must repeat race-sensitive checks while holding
 * the project lock before it writes any state.
 */
final readonly class GetStartProjectPreflight
{
    /**
     * Reuse the authoritative completeness rules from AIOS-029 and AIOS-040.
     */
    public function __construct(
        private EvaluateProjectCompleteness $evaluateProjectCompleteness,
    ) {}

    /**
     * Return exact configuration, documents, integration, cost, and gate data.
     */
    public function handle(
        int $organizationId,
        int $projectId,
    ): StartProjectPreflightResult {
        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();

        $configuration = ProjectConfiguration::query()
            ->where('project_id', $project->id)
            ->first();

        $setupProgress = ProjectSetupProgress::query()
            ->where('project_id', $project->id)
            ->first();

        $integration = ProjectIntegration::query()
            ->forOrganization($organizationId)
            ->forProject($project->id)
            ->where('provider', IntegrationProvider::Notion->value)
            ->first();

        /*
         * Exclude ciphertext at query time. Preflight needs only credential
         * version metadata to determine whether the connection test is current.
         */
        $credential = ProviderCredential::query()
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
            ->where('provider', IntegrationProvider::Notion->value)
            ->first();

        $configurationVersion = ProjectConfigurationVersion::query()
            ->where('project_id', $project->id)
            ->orderByDesc('revision')
            ->first();

        $latestSnapshot = ProjectContextSnapshot::query()
            ->where('project_id', $project->id)
            ->latest('id')
            ->first();

        /*
         * Fail closed when any project execution is still non-terminal.
         *
         * At ready_for_planning there should be no unrelated project execution.
         * AIOS-063 will re-check this condition under a project row lock.
         */
        $activeExecutions = Execution::query()
            ->forProject($project->id)
            ->whereNotIn('status', [
                ExecutionStatus::Completed->value,
                ExecutionStatus::Failed->value,
                ExecutionStatus::Cancelled->value,
            ])
            ->orderBy('created_at')
            ->get([
                'id',
                'capability',
                'status',
                'idempotency_key',
                'created_at',
            ]);

        $completeness = $this->evaluateProjectCompleteness->handle(
            organizationId: $organizationId,
            projectId: $project->id,
        );

        $documents = $this->documents(
            project: $project,
            configuration: $configuration,
        );

        $blockers = $this->blockers(
            project: $project,
            configuration: $configuration,
            configurationVersion: $configurationVersion,
            completenessIssues: $completeness->issues,
            activeExecutionCount: $activeExecutions->count(),
        );

        $approvalPolicy = $configuration->approval_policy ?? [];

        if (! is_array($approvalPolicy)) {
            $approvalPolicy = [];
        }

        $budgetLimitMinor = $configuration?->budget_limit_minor;

        return new StartProjectPreflightResult(
            projectId: $project->id,
            projectStatus: $project->status->value,
            canStart: $blockers === [],
            configuration: [
                'ready' => $configuration !== null
                    && $configuration->usesCurrentSchema()
                    && $this->sectionBlockers(
                        blockers: $blockers,
                        category: 'configuration',
                    ) === [],
                'schema_version' => $configuration?->schema_version,
                'revision' => $configuration?->revision,
                'values' => $configuration?->usesCurrentSchema() === true
                    ? $configuration->toVersionedArray()
                    : null,
                'blockers' => $this->sectionBlockers(
                    blockers: $blockers,
                    category: 'configuration',
                ),
            ],
            documents: [
                ...$documents,
                'ready' => $configuration !== null
                    && $documents['required_classes'] !== []
                    && $documents['missing_classes'] === []
                    && $this->sectionBlockers(
                        blockers: $blockers,
                        category: 'documents',
                    ) === [],
                'blockers' => $this->sectionBlockers(
                    blockers: $blockers,
                    category: 'documents',
                ),
            ],
            integration: [
                'ready' => $integration !== null
                    && $credential !== null
                    && $this->sectionBlockers(
                        blockers: $blockers,
                        category: 'integration',
                    ) === [],
                'provider' => IntegrationProvider::Notion->value,
                'connection' => $integration?->toSafeMetadata(),
                'credential' => $credential?->toSafeMetadata() ?? [
                    'provider' => IntegrationProvider::Notion->value,
                    'configured' => false,
                    'version' => null,
                    'created_at' => null,
                    'rotated_at' => null,
                ],
                'blockers' => $this->sectionBlockers(
                    blockers: $blockers,
                    category: 'integration',
                ),
            ],
            cost: [
                'ready' => $configuration !== null
                    && $this->sectionBlockers(
                        blockers: $blockers,
                        category: 'cost',
                    ) === [],
                'mode' => match (true) {
                    $configuration === null => 'unconfigured',
                    $budgetLimitMinor === null => 'unbounded',
                    $budgetLimitMinor === 0 => 'blocked',
                    default => 'capped',
                },
                'budget_limit_minor' => $budgetLimitMinor,
                'budget_currency' => $configuration?->budget_currency,
                'automatic_retry_limit' => $configuration
                    ?->automatic_retry_limit,
                /*
                 * AIOS-062 reports configured cost controls only.
                 *
                 * It does not fabricate an estimated planning cost before the
                 * later usage and cost-estimation implementation exists.
                 */
                'estimated_planning_cost_minor' => null,
                'estimate_status' => 'not_available_in_mvp_preflight',
                'blockers' => $this->sectionBlockers(
                    blockers: $blockers,
                    category: 'cost',
                ),
            ],
            approvalGates: [
                'ready' => $configuration !== null
                    && $setupProgress?->isComplete() === true
                    && $this->sectionBlockers(
                        blockers: $blockers,
                        category: 'approval',
                    ) === [],
                'setup_review_confirmed' => $setupProgress?->isComplete()
                    ?? false,
                /*
                 * The Notion ticket explicitly does not require a separate
                 * human approval merely to execute the preflight/start request.
                 */
                'start_requires_additional_approval' => false,
                'roadmap_required' => $approvalPolicy['roadmap_required'] ?? null,
                'ticket_execution_required' => $approvalPolicy['ticket_execution_required'] ?? null,
                'merge_required' => $approvalPolicy['merge_required'] ?? null,
                'blockers' => $this->sectionBlockers(
                    blockers: $blockers,
                    category: 'approval',
                ),
            ],
            execution: [
                'ready' => $activeExecutions->isEmpty(),
                'active_count' => $activeExecutions->count(),
                'active' => $activeExecutions
                    ->map(static fn (Execution $execution): array => [
                        'id' => $execution->id,
                        'capability' => $execution->capability,
                        'status' => $execution->status->value,
                        'idempotency_key' => $execution->idempotency_key,
                        'created_at' => $execution->created_at
                            ?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                'blockers' => $this->sectionBlockers(
                    blockers: $blockers,
                    category: 'execution',
                ),
            ],
            contextSnapshot: [
                'ready' => $configuration !== null
                    && $configurationVersion !== null
                    && $documents['missing_classes'] === []
                    && $this->sectionBlockers(
                        blockers: $blockers,
                        category: 'context',
                    ) === [],
                /*
                 * Snapshot creation belongs to AIOS-063. The preflight query
                 * only reports whether its exact immutable inputs exist.
                 */
                'will_create_or_reuse_on_start' => true,
                'current_configuration_revision' => $configuration?->revision,
                'configuration_version_id' => $configurationVersion?->id,
                'configuration_version_revision' => $configurationVersion
                    ?->revision,
                'latest_snapshot_id' => $latestSnapshot?->id,
                'latest_snapshot_configuration_revision' => $latestSnapshot
                    ?->configuration_revision,
                'blockers' => $this->sectionBlockers(
                    blockers: $blockers,
                    category: 'context',
                ),
            ],
            blockers: $blockers,
        );
    }

    /**
     * Return required classes and immutable approved-version evidence.
     *
     * @return array{
     *     required_classes: list<string>,
     *     approved_classes: list<string>,
     *     missing_classes: list<string>,
     *     approved_versions: list<array<string, mixed>>
     * }
     */
    private function documents(
        Project $project,
        ?ProjectConfiguration $configuration,
    ): array {
        $requiredClasses = [];

        foreach ($configuration->required_documents ?? [] as $value) {
            if (trim($value) === '') {
                continue;
            }

            $requiredClasses[] = trim($value);
        }

        $requiredClasses = array_values(array_unique($requiredClasses));
        sort($requiredClasses, SORT_STRING);

        /*
         * These constraints intentionally match the existing project
         * completeness and immutable context-snapshot requirements.
         */
        $approvedVersions = DocumentVersion::query()
            ->select([
                'id',
                'document_id',
                'version',
                'checksum_sha256',
                'classification',
                'analyzer_name',
                'analyzer_version',
                'analysis_seed',
                'analysis_completed_at',
                'analysis_flags',
            ])
            ->with(['document:id,project_id,title,document_class'])
            ->where('status', DocumentStatus::Approved->value)
            ->whereNotNull('analyzer_name')
            ->whereNotNull('analyzer_version')
            ->whereNotNull('analysis_seed')
            ->whereNotNull('analysis_completed_at')
            ->whereNotNull('analysis_summary')
            ->whereNotNull('analysis_conflicts')
            ->whereNotNull('analysis_gaps')
            ->whereNotNull('analysis_flags')
            ->whereHas(
                'document',
                fn ($query) => $query->where('project_id', $project->id),
            )
            ->orderBy('document_id')
            ->orderBy('version')
            ->get();

        $approvedClasses = [];
        $approvedVersionRows = [];

        foreach ($approvedVersions as $version) {
            $documentClass = $version->document->document_class;

            if (is_string($documentClass) && trim($documentClass) !== '') {
                $approvedClasses[] = trim($documentClass);
            }

            $approvedVersionRows[] = [
                'document_id' => $version->document_id,
                'document_version_id' => $version->id,
                'document_class' => $documentClass,
                'title' => $version->document->title,
                'version' => $version->version,
                'checksum_sha256' => $version->checksum_sha256,
                'classification' => $version->classification->value,
                'analyzer_name' => $version->analyzer_name,
                'analyzer_version' => $version->analyzer_version,
                'analysis_seed' => $version->analysis_seed,
                'analysis_completed_at' => $version
                    ->analysis_completed_at
                    ?->toIso8601String(),
                'analysis_flags' => $version->analysis_flags,
            ];
        }

        $approvedClasses = array_values(array_unique($approvedClasses));
        sort($approvedClasses, SORT_STRING);

        return [
            'required_classes' => $requiredClasses,
            'approved_classes' => $approvedClasses,
            'missing_classes' => array_values(array_diff(
                $requiredClasses,
                $approvedClasses,
            )),
            'approved_versions' => $approvedVersionRows,
        ];
    }

    /**
     * Build all ordered blockers without mutating project state.
     *
     * @param  list<ProjectCompletenessIssue>  $completenessIssues
     * @return list<array{
     *     key: string,
     *     category: string,
     *     message: string,
     *     remediation: string
     * }>
     */
    private function blockers(
        Project $project,
        ?ProjectConfiguration $configuration,
        ?ProjectConfigurationVersion $configurationVersion,
        array $completenessIssues,
        int $activeExecutionCount,
    ): array {
        $blockers = [];

        if ($project->isArchived()) {
            $blockers[] = $this->blocker(
                key: 'project.archived',
                category: 'project',
                message: 'The project is archived and cannot be started.',
                remediation: 'Restore the project before running StartProject.',
            );
        }

        if ($project->status !== ProjectStatus::ReadyForPlanning) {
            $blockers[] = $this->blocker(
                key: 'project.status',
                category: 'project',
                message: sprintf(
                    'Project status "%s" does not allow StartProject.',
                    $project->status->value,
                ),
                remediation: 'Resolve setup and document blockers, then transition the project to ready_for_planning through the guarded lifecycle.',
            );
        }

        foreach ($completenessIssues as $issue) {
            $blockers[] = $this->blocker(
                key: $issue->key,
                category: match (true) {
                    str_starts_with(
                        $issue->key,
                        'required_documents',
                    ) => 'documents',
                    str_starts_with(
                        $issue->key,
                        'integrations.notion',
                    ) => 'integration',
                    $issue->key === 'setup.review' => 'approval',
                    default => 'configuration',
                },
                message: $issue->message,
                remediation: $issue->remediation,
            );
        }

        if ($activeExecutionCount > 0) {
            $blockers[] = $this->blocker(
                key: 'execution.active',
                category: 'execution',
                message: sprintf(
                    '%d active project execution(s) already exist.',
                    $activeExecutionCount,
                ),
                remediation: 'Wait for the active execution to finish, cancel it through the resilience manager, or recover it before starting again.',
            );
        }

        if ($configuration?->budget_limit_minor === 0) {
            $blockers[] = $this->blocker(
                key: 'cost.budget_limit',
                category: 'cost',
                message: 'The project planning budget is set to zero.',
                remediation: 'Set a positive project budget or deliberately select the unbounded budget option.',
            );
        }

        if ($configuration !== null && $configurationVersion === null) {
            $blockers[] = $this->blocker(
                key: 'context.configuration_version',
                category: 'context',
                message: 'No immutable project configuration version exists.',
                remediation: 'Save the current project configuration through the setup workflow before start.',
            );
        } elseif (
            $configuration !== null
            && $configurationVersion->revision !== $configuration->revision
        ) {
            $blockers[] = $this->blocker(
                key: 'context.configuration_version',
                category: 'context',
                message: 'The immutable configuration version is stale.',
                remediation: 'Record the current configuration revision before running StartProject.',
            );
        }

        return $blockers;
    }

    /**
     * Return blockers for one report section.
     *
     * @param  list<array{
     *     key: string,
     *     category: string,
     *     message: string,
     *     remediation: string
     * }>  $blockers
     * @return list<array{
     *     key: string,
     *     category: string,
     *     message: string,
     *     remediation: string
     * }>
     */
    private function sectionBlockers(
        array $blockers,
        string $category,
    ): array {
        return array_values(array_filter(
            $blockers,
            static fn (array $blocker): bool => $blocker['category'] === $category,
        ));
    }

    /**
     * Create one normalized, browser-safe preflight blocker.
     *
     * @return array{
     *     key: string,
     *     category: string,
     *     message: string,
     *     remediation: string
     * }
     */
    private function blocker(
        string $key,
        string $category,
        string $message,
        string $remediation,
    ): array {
        return [
            'key' => $key,
            'category' => $category,
            'message' => $message,
            'remediation' => $remediation,
        ];
    }
}
