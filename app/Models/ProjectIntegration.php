<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionFailureCode;
use App\Domain\Integrations\NotionConnectionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores safe project-scoped external integration metadata.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property IntegrationProvider $provider
 * @property string|null $workspace_id
 * @property string|null $workspace_name
 * @property string|null $database_id
 * @property string|null $database_name
 * @property string|null $data_source_id
 * @property string|null $data_source_name
 * @property NotionConnectionStatus $connection_status
 * @property NotionConnectionFailureCode|null $last_failure_code
 * @property string|null $last_provider_request_id
 * @property int|null $last_tested_by_user_id
 * @property CarbonImmutable $last_tested_at
 * @property CarbonImmutable|null $last_connected_at
 */
#[Fillable([
    'organization_id',
    'project_id',
    'provider',
    'workspace_id',
    'workspace_name',
    'database_id',
    'database_name',
    'data_source_id',
    'data_source_name',
    'connection_status',
    'last_failure_code',
    'last_provider_request_id',
    'last_tested_by_user_id',
    'last_tested_at',
    'last_connected_at',
])]
final class ProjectIntegration extends Model
{
    /**
     * Return the owning organization.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Return the owning project.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the user that performed the latest connection test.
     *
     * @return BelongsTo<User, $this>
     */
    public function lastTestedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'last_tested_by_user_id',
        );
    }

    /**
     * Scope integrations to one explicit tenant.
     *
     * @param  Builder<ProjectIntegration>  $query
     * @return Builder<ProjectIntegration>
     */
    public function scopeForOrganization(
        Builder $query,
        int $organizationId,
    ): Builder {
        return $query->where(
            $query->getModel()->qualifyColumn('organization_id'),
            $organizationId,
        );
    }

    /**
     * Scope integrations to one explicit project.
     *
     * @param  Builder<ProjectIntegration>  $query
     * @return Builder<ProjectIntegration>
     */
    public function scopeForProject(
        Builder $query,
        int $projectId,
    ): Builder {
        return $query->where(
            $query->getModel()->qualifyColumn('project_id'),
            $projectId,
        );
    }

    /**
     * Return metadata safe for an authorized user interface.
     *
     * @return array<string, mixed>
     */
    public function toSafeMetadata(): array
    {
        return [
            'provider' => $this->provider->value,
            'status' => $this->connection_status->value,
            'workspace_id' => $this->workspace_id,
            'workspace_name' => $this->workspace_name,
            'database_id' => $this->database_id,
            'database_name' => $this->database_name,
            'data_source_id' => $this->data_source_id,
            'data_source_name' => $this->data_source_name,
            'last_failure_code' => $this->last_failure_code?->value,
            'last_tested_at' => $this->last_tested_at->toIso8601String(),
            'last_connected_at' => $this->last_connected_at?->toIso8601String(),
        ];
    }

    /**
     * Cast persisted integration state to domain-safe values.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'connection_status' => NotionConnectionStatus::class,
            'last_failure_code' => NotionConnectionFailureCode::class,
            'last_tested_at' => 'immutable_datetime',
            'last_connected_at' => 'immutable_datetime',
        ];
    }
}
