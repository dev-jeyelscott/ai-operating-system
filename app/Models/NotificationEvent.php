<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\NotificationEventFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stores one sanitized user-facing notification derived from a domain event.
 *
 * The source domain-event payload must not be copied blindly into this record.
 * Only display-safe content required by the in-app experience belongs here.
 *
 * @property string $id
 * @property int $organization_id
 * @property int|null $project_id
 * @property string $source_event_id
 * @property string $event_name
 * @property string $title
 * @property string $message
 * @property string|null $action_url
 * @property array<string, mixed> $data
 * @property string $correlation_id
 * @property string|null $execution_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 * @property-read Organization $organization
 * @property-read Project|null $project
 * @property-read Collection<int, NotificationRecipient> $recipients
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'organization_id',
    'project_id',
    'source_event_id',
    'event_name',
    'title',
    'message',
    'action_url',
    'data',
    'correlation_id',
    'execution_id',
    'occurred_at',
])]
final class NotificationEvent extends Model
{
    /** @use HasFactory<NotificationEventFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Notification events store created_at but intentionally have no updated_at.
     */
    public $timestamps = false;

    /**
     * Return the organization that owns this notification event.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Return the optional project associated with this notification event.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return assigned recipients in deterministic creation order.
     *
     * @return HasMany<NotificationRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Scope notification events to one explicit organization.
     *
     * @param  Builder<NotificationEvent>  $query
     * @return Builder<NotificationEvent>
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
     * Scope notification events to one explicit project.
     *
     * @param  Builder<NotificationEvent>  $query
     * @return Builder<NotificationEvent>
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
     * Scope notification events to one originating domain-event identifier.
     *
     * @param  Builder<NotificationEvent>  $query
     * @return Builder<NotificationEvent>
     */
    public function scopeForSourceEvent(
        Builder $query,
        string $sourceEventId,
    ): Builder {
        return $query->where(
            $query->getModel()->qualifyColumn('source_event_id'),
            $sourceEventId,
        );
    }

    /**
     * Cast persisted JSON and timestamps into domain-safe values.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'project_id' => 'integer',
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
