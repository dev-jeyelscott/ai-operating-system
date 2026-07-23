<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Projects\Configuration\AutonomyLevel;
use App\Domain\Projects\Configuration\ProjectConfigurationSchema;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Projects\Configuration\RepositoryProvider;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectConfigurationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores the current versioned configuration aggregate for one project.
 *
 * Credentials and secrets must never be persisted in this model.
 *
 * @property int $id
 * @property int $project_id
 * @property int $schema_version
 * @property int $revision
 * @property array<string, array<int, string>> $technology_stack
 * @property RepositoryProvider|null $repository_provider
 * @property string|null $repository_url
 * @property string|null $default_branch
 * @property string $integration_branch
 * @property string|null $build_command
 * @property string|null $test_command
 * @property string|null $lint_command
 * @property string|null $static_analysis_command
 * @property string|null $security_command
 * @property array<int, string> $required_documents
 * @property ReasoningLevel $default_reasoning
 * @property array<string, array<int, string>> $provider_policy
 * @property int|null $budget_limit_minor
 * @property string $budget_currency
 * @property int $automatic_retry_limit
 * @property AutonomyLevel $autonomy_level
 * @property array<string, bool> $approval_policy
 * @property array<string, array<int, string>> $notification_policy
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'technology_stack',
    'repository_provider',
    'repository_url',
    'default_branch',
    'integration_branch',
    'build_command',
    'test_command',
    'lint_command',
    'static_analysis_command',
    'security_command',
    'required_documents',
    'default_reasoning',
    'provider_policy',
    'budget_limit_minor',
    'budget_currency',
    'automatic_retry_limit',
    'autonomy_level',
    'approval_policy',
    'notification_policy',
])]
final class ProjectConfiguration extends Model
{
    /** @use HasFactory<ProjectConfigurationFactory> */
    use HasFactory;

    /**
     * Apply safe defaults when a project configuration is initialized.
     *
     * schema_version, revision, and project_id are intentionally excluded from
     * mass assignment. They are controlled by the aggregate and persistence
     * implementation.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'schema_version' => ProjectConfigurationSchema::CURRENT_VERSION,
        'revision' => ProjectConfigurationSchema::INITIAL_REVISION,
        'technology_stack' => ProjectConfigurationSchema::TECHNOLOGY_STACK_DEFAULT_JSON,
        'integration_branch' => 'develop',
        'required_documents' => '[]',
        'default_reasoning' => 'medium',
        'provider_policy' => ProjectConfigurationSchema::PROVIDER_POLICY_DEFAULT_JSON,
        'budget_currency' => 'USD',
        'automatic_retry_limit' => 3,
        'autonomy_level' => 'approval_required',
        'approval_policy' => ProjectConfigurationSchema::APPROVAL_POLICY_DEFAULT_JSON,
        'notification_policy' => ProjectConfigurationSchema::NOTIFICATION_POLICY_DEFAULT_JSON,
    ];

    /**
     * Return the project that owns this configuration aggregate.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Determine whether this record uses the schema understood by this release.
     */
    public function usesCurrentSchema(): bool
    {
        return ProjectConfigurationSchema::supports($this->schema_version);
    }

    /**
     * Serialize persistence columns into the stable schema-v1 contract.
     *
     * This credential-free payload can later be copied into immutable
     * configuration-history and project-context snapshots.
     *
     * @return array<string, mixed>
     */
    public function toVersionedArray(): array
    {
        ProjectConfigurationSchema::assertSupported($this->schema_version);

        return [
            'schema_version' => $this->schema_version,
            'revision' => $this->revision,
            'technology_stack' => $this->technology_stack,
            'repository' => [
                'provider' => $this->repository_provider?->value,
                'url' => $this->repository_url,
                'default_branch' => $this->default_branch,
                'integration_branch' => $this->integration_branch,
            ],
            'validation_commands' => [
                'build' => $this->build_command,
                'test' => $this->test_command,
                'lint' => $this->lint_command,
                'static_analysis' => $this->static_analysis_command,
                'security' => $this->security_command,
            ],
            'required_documents' => $this->required_documents,
            'policy' => [
                'default_reasoning' => $this->default_reasoning->value,
                'provider' => $this->provider_policy,
                'budget' => [
                    'limit_minor' => $this->budget_limit_minor,
                    'currency' => $this->budget_currency,
                ],
                'automatic_retry_limit' => $this->automatic_retry_limit,
                'autonomy_level' => $this->autonomy_level->value,
                'approval' => $this->approval_policy,
            ],
            'notifications' => $this->notification_policy,
            'integrations' => $this->versionedIntegrations(),
        ];
    }

    /**
     * Capture credential-free integration targets alongside this revision.
     *
     * @return array<string, array<string, string|null>>
     */
    private function versionedIntegrations(): array
    {
        return ProjectIntegration::query()
            ->where('project_id', $this->project_id)
            ->get()
            ->mapWithKeys(static fn (ProjectIntegration $integration): array => [
                $integration->provider->value => [
                    'workspace_id' => $integration->workspace_id,
                    'workspace_name' => $integration->workspace_name,
                    'database_id' => $integration->database_id,
                    'database_name' => $integration->database_name,
                    'data_source_id' => $integration->data_source_id,
                    'data_source_name' => $integration->data_source_name,
                ],
            ])
            ->all();
    }

    /**
     * Cast persisted values to domain-safe types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'revision' => 'integer',
            'technology_stack' => 'array',
            'repository_provider' => RepositoryProvider::class,
            'required_documents' => 'array',
            'default_reasoning' => ReasoningLevel::class,
            'provider_policy' => 'array',
            'budget_limit_minor' => 'integer',
            'automatic_retry_limit' => 'integer',
            'autonomy_level' => AutonomyLevel::class,
            'approval_policy' => 'array',
            'notification_policy' => 'array',
        ];
    }
}
