<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tickets\TicketActualState;
use App\Domain\Tickets\TicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $roadmap_id
 * @property int|null $roadmap_phase_id
 * @property int|null $roadmap_milestone_id
 * @property string $stable_id
 * @property string $title
 * @property string $objective
 * @property string $ticket_type
 * @property array{included:list<string>, excluded:list<string>} $scope
 * @property list<array<string, mixed>> $acceptance_criteria
 * @property list<array<string, mixed>> $source_references
 * @property list<string> $evidence_requirements
 * @property array<string, mixed>|null $notion_body_overrides
 * @property string $priority
 * @property string $risk
 * @property string $reasoning_level
 * @property string $reasoning
 * @property string $logical_agent
 * @property int $estimated_complexity
 * @property bool $human_approval_required
 * @property int $position
 * @property int|null $critical_path_rank
 * @property int|null $critical_path_position
 * @property bool $is_critical_path
 * @property TicketStatus $status
 * @property TicketStatus $desired_state
 * @property string|null $reported_state
 * @property string|null $observed_state
 * @property TicketActualState $actual_state
 * @property CarbonImmutable $status_changed_at
 * @property CarbonImmutable|null $ready_at
 * @property-read Roadmap $roadmap
 * @property-read RoadmapPhase|null $phase
 * @property-read RoadmapMilestone|null $milestone
 * @property-read Collection<int, TaskDependency> $dependencies
 * @property-read Collection<int, RoadmapTraceabilityLink> $traceabilityLinks
 * @property-read Collection<int, ExternalTicketMapping> $externalTicketMappings
 */
#[DateFormat('Y-m-d H:i:s.u')]
final class RoadmapTask extends Model
{
    protected $guarded = [];

    private bool $authoritativeStatusTransition = false;

    /**
     * Keep model-created tasks aligned with database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'backlog',
        'desired_state' => 'backlog',
        'actual_state' => 'unverified',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $ticket): void {
            if ($ticket->isDirty([
                'status',
                'status_changed_at',
                'ready_at',
            ]) && ! $ticket->authoritativeStatusTransition) {
                throw new LogicException(
                    'Authoritative ticket status must be changed through TransitionTicketStatus.',
                );
            }
        });
    }

    /**
     * Persist fields already approved by the authoritative transition service.
     */
    public function applyAuthoritativeStatusTransition(
        TicketStatus $target,
        CarbonImmutable $occurredAt,
    ): void {
        $this->authoritativeStatusTransition = true;

        try {
            $updates = [
                'status' => $target,
                'status_changed_at' => $occurredAt,
            ];

            if ($target === TicketStatus::Ready && $this->ready_at === null) {
                $updates['ready_at'] = $occurredAt;
            }

            $this->forceFill($updates)->save();
        } finally {
            $this->authoritativeStatusTransition = false;
        }
    }

    /**
     * Return the roadmap that owns this ticket.
     *
     * @return BelongsTo<Roadmap, $this>
     */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
    }

    /**
     * Return the hard dependency records declared by this ticket.
     *
     * @return HasMany<TaskDependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class);
    }

    /**
     * Return the roadmap phase containing this ticket.
     *
     * @return BelongsTo<RoadmapPhase, $this>
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(
            RoadmapPhase::class,
            'roadmap_phase_id',
        );
    }

    /**
     * Return the roadmap milestone containing this ticket.
     *
     * @return BelongsTo<RoadmapMilestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(
            RoadmapMilestone::class,
            'roadmap_milestone_id',
        );
    }

    /**
     * Return the source-document traceability links for this ticket.
     *
     * @return HasMany<RoadmapTraceabilityLink, $this>
     */
    public function traceabilityLinks(): HasMany
    {
        return $this->hasMany(
            RoadmapTraceabilityLink::class,
        );
    }

    /**
     * Return the external publication mappings associated with this ticket.
     *
     * These records preserve the stable relationship between the internal
     * roadmap task and external systems such as Notion.
     *
     * @return HasMany<ExternalTicketMapping, $this>
     */
    public function externalTicketMappings(): HasMany
    {
        return $this->hasMany(
            ExternalTicketMapping::class,
            'roadmap_task_id',
        );
    }

    /**
     * Cast persisted task content and ticket states to stable PHP types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'acceptance_criteria' => 'array',
            'source_references' => 'array',
            'evidence_requirements' => 'array',
            'notion_body_overrides' => 'array',
            'human_approval_required' => 'boolean',
            'estimated_complexity' => 'integer',
            'position' => 'integer',
            'critical_path_rank' => 'integer',
            'critical_path_position' => 'integer',
            'is_critical_path' => 'boolean',
            'status' => TicketStatus::class,
            'desired_state' => TicketStatus::class,
            'actual_state' => TicketActualState::class,
            'status_changed_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
        ];
    }
}
