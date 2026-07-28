<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Stores one durable ticket claim and its lease lifecycle.
 *
 * An unreleased row remains authoritative even after expires_at. AIOS-093 is
 * responsible for confirming execution liveness before releasing or reclaiming
 * an expired lease.
 *
 * @property string $id
 * @property int $project_id
 * @property int $roadmap_task_id
 * @property string $execution_id
 * @property string $owner
 * @property CarbonImmutable $acquired_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $heartbeat_at
 * @property CarbonImmutable|null $released_at
 * @property string|null $release_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read RoadmapTask $ticket
 * @property-read Execution $execution
 */
#[DateFormat('Y-m-d H:i:s.u')]
final class TicketExecutionLease extends Model
{
    use HasUlids;

    /**
     * Allow only the fields required to acquire or maintain a lease.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'roadmap_task_id',
        'execution_id',
        'owner',
        'acquired_at',
        'expires_at',
        'heartbeat_at',
        'released_at',
        'release_reason',
    ];

    /**
     * Protect immutable claim identity and preserve lease history.
     */
    protected static function booted(): void
    {
        self::updating(static function (self $lease): void {
            foreach (
                [
                    'project_id',
                    'roadmap_task_id',
                    'execution_id',
                    'owner',
                    'acquired_at',
                ] as $attribute
            ) {
                if ($lease->isDirty($attribute)) {
                    throw new LogicException(
                        'Ticket execution lease identity is immutable.',
                    );
                }
            }
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'Ticket execution lease history cannot be deleted.',
            );
        });
    }

    /**
     * Return the project boundary that owns this lease.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the ticket claimed by this lease.
     *
     * @return BelongsTo<RoadmapTask, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(
            RoadmapTask::class,
            'roadmap_task_id',
        );
    }

    /**
     * Return the logical execution that owns this lease.
     *
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * Limit a query to leases not yet released by recovery policy.
     *
     * @param  Builder<TicketExecutionLease>  $query
     * @return Builder<TicketExecutionLease>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull(
            $query->getModel()->qualifyColumn('released_at'),
        );
    }

    /**
     * Determine whether recovery policy has released this lease.
     */
    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    /**
     * Cast persisted timestamps to immutable values.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'roadmap_task_id' => 'integer',
            'acquired_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }
}
