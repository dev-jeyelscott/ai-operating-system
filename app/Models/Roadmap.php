<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $project_id
 * @property string $planning_execution_id
 * @property int $project_context_snapshot_id
 * @property int|null $parent_roadmap_id
 * @property string|null $approval_id
 * @property int $schema_version
 * @property int $revision
 * @property int $content_version
 * @property string $provider_id
 * @property string|null $scenario
 * @property int|null $seed
 * @property string $input_fingerprint
 * @property string $output_fingerprint
 * @property string $candidate_fingerprint
 * @property string|null $approved_fingerprint
 * @property string $status
 * @property string $readiness
 * @property string $goal
 * @property list<string> $scope
 * @property list<string> $assumptions
 * @property list<string> $constraints
 * @property list<string> $definition_of_done
 * @property list<string> $required_approvals
 * @property list<array<string, mixed>> $document_inventory
 * @property string $document_summary
 * @property list<string> $architecture_concerns
 * @property list<string> $security_concerns
 * @property list<string> $readiness_reasons
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $derived_graph
 * @property array<string, mixed> $generated_snapshot
 * @property array<string, mixed>|null $approved_snapshot
 * @property string|null $regeneration_feedback
 * @property string|null $feedback_fingerprint
 * @property CarbonImmutable $generated_at
 * @property CarbonImmutable|null $approved_at
 * @property-read Project $project
 * @property-read Execution $execution
 * @property-read ProjectContextSnapshot $contextSnapshot
 * @property-read Roadmap|null $parent
 * @property-read Approval|null $approval
 * @property-read Collection<int, RoadmapPhase> $phases
 * @property-read Collection<int, RoadmapMilestone> $milestones
 * @property-read Collection<int, RoadmapTask> $tasks
 * @property-read Collection<int, RoadmapEdit> $edits
 * @property-read Collection<int, RoadmapTraceabilityLink> $traceabilityLinks
 * @property-read Collection<int, PlanningExecutionDiagnostic> $diagnostics
 */
final class Roadmap extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(static function (self $roadmap): void {
            foreach ([
                'project_id', 'planning_execution_id', 'project_context_snapshot_id',
                'parent_roadmap_id', 'schema_version', 'revision', 'provider_id',
                'scenario', 'seed', 'input_fingerprint', 'output_fingerprint',
                'goal', 'scope', 'assumptions', 'constraints', 'definition_of_done',
                'required_approvals', 'document_inventory', 'document_summary',
                'architecture_concerns', 'security_concerns', 'derived_graph',
                'generated_snapshot', 'generated_at',
            ] as $attribute) {
                if ($roadmap->isDirty($attribute)) {
                    throw new LogicException('Provider-generated roadmap content is immutable.');
                }
            }
            if ($roadmap->getOriginal('approved_snapshot') !== null
                && $roadmap->isDirty(['approved_snapshot', 'approved_fingerprint', 'approved_at'])) {
                throw new LogicException('Approved roadmap content is immutable.');
            }
        });

        self::deleting(static function (): void {
            throw new LogicException('Roadmap revisions cannot be deleted.');
        });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class, 'planning_execution_id');
    }

    /** @return BelongsTo<ProjectContextSnapshot, $this> */
    public function contextSnapshot(): BelongsTo
    {
        return $this->belongsTo(ProjectContextSnapshot::class, 'project_context_snapshot_id');
    }

    /** @return BelongsTo<Roadmap, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_roadmap_id');
    }

    /** @return BelongsTo<Approval, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    /** @return HasMany<Roadmap, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_roadmap_id')->orderBy('revision');
    }

    /** @return HasMany<RoadmapPhase, $this> */
    public function phases(): HasMany
    {
        return $this->hasMany(RoadmapPhase::class)->orderBy('position');
    }

    /** @return HasMany<RoadmapMilestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(RoadmapMilestone::class)->orderBy('position');
    }

    /** @return HasMany<RoadmapTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(RoadmapTask::class)->orderBy('position');
    }

    /** @return HasMany<RoadmapEdit, $this> */
    public function edits(): HasMany
    {
        return $this->hasMany(RoadmapEdit::class)->orderBy('content_version');
    }

    /** @return HasMany<RoadmapTraceabilityLink, $this> */
    public function traceabilityLinks(): HasMany
    {
        return $this->hasMany(RoadmapTraceabilityLink::class);
    }

    /** @return HasMany<PlanningExecutionDiagnostic, $this> */
    public function diagnostics(): HasMany
    {
        return $this->hasMany(PlanningExecutionDiagnostic::class, 'execution_id', 'planning_execution_id');
    }

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'revision' => 'integer',
            'content_version' => 'integer',
            'seed' => 'integer',
            'scope' => 'array',
            'assumptions' => 'array',
            'constraints' => 'array',
            'definition_of_done' => 'array',
            'required_approvals' => 'array',
            'document_inventory' => 'array',
            'architecture_concerns' => 'array',
            'security_concerns' => 'array',
            'readiness_reasons' => 'array',
            'metadata' => 'array',
            'derived_graph' => 'array',
            'generated_snapshot' => 'array',
            'approved_snapshot' => 'array',
            'generated_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }
}
