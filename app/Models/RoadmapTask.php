<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property string $priority
 * @property string $risk
 * @property string $reasoning_level
 * @property string $reasoning
 * @property string $logical_agent
 * @property int $estimated_complexity
 * @property bool $human_approval_required
 * @property array<string, list<string>>|null $notion_body_overrides
 * @property int $position
 * @property int|null $critical_path_rank
 * @property int|null $critical_path_position
 * @property bool $is_critical_path
 * @property-read Roadmap $roadmap
 * @property-read RoadmapPhase|null $phase
 * @property-read RoadmapMilestone|null $milestone
 * @property-read Collection<int, TaskDependency> $dependencies
 * @property-read Collection<int, RoadmapTraceabilityLink> $traceabilityLinks
 */
final class RoadmapTask extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Roadmap, $this> */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
    }

    /** @return HasMany<TaskDependency, $this> */
    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class);
    }

    /** @return BelongsTo<RoadmapPhase, $this> */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(RoadmapPhase::class, 'roadmap_phase_id');
    }

    /** @return BelongsTo<RoadmapMilestone, $this> */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(RoadmapMilestone::class, 'roadmap_milestone_id');
    }

    /** @return HasMany<RoadmapTraceabilityLink, $this> */
    public function traceabilityLinks(): HasMany
    {
        return $this->hasMany(RoadmapTraceabilityLink::class);
    }

    /** @return HasMany<ExternalTicketMapping, $this> */
    public function externalTicketMappings(): HasMany
    {
        return $this->hasMany(ExternalTicketMapping::class);
    }

    protected function casts(): array
    {
        return ['scope' => 'array', 'acceptance_criteria' => 'array', 'source_references' => 'array', 'evidence_requirements' => 'array', 'notion_body_overrides' => 'array', 'human_approval_required' => 'boolean', 'estimated_complexity' => 'integer', 'position' => 'integer', 'critical_path_rank' => 'integer', 'critical_path_position' => 'integer', 'is_critical_path' => 'boolean'];
    }
}
