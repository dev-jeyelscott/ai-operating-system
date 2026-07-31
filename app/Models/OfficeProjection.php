<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores the rebuildable office read model for one organization project.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property int $schema_version
 * @property int $last_event_sequence
 * @property string|null $last_event_id
 * @property string $fingerprint
 * @property array<string, mixed> $state
 * @property CarbonImmutable $projected_at
 * @property CarbonImmutable|null $rebuilt_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'organization_id',
    'project_id',
    'schema_version',
    'last_event_sequence',
    'last_event_id',
    'fingerprint',
    'state',
    'projected_at',
    'rebuilt_at',
])]
final class OfficeProjection extends Model
{
    /**
     * Return the organization that owns this projection.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Return the project represented by this projection.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Limit projections to one explicit organization.
     *
     * @param  Builder<OfficeProjection>  $query
     * @return Builder<OfficeProjection>
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
     * Limit projections to one explicit project.
     *
     * @param  Builder<OfficeProjection>  $query
     * @return Builder<OfficeProjection>
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
     * Cast persisted values to immutable and JSON-safe application types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'project_id' => 'integer',
            'schema_version' => 'integer',
            'last_event_sequence' => 'integer',
            'state' => 'array',
            'projected_at' => 'immutable_datetime',
            'rebuilt_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
