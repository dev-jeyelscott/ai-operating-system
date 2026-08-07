<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WorkflowInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Stores the mutable state projection for one workflow execution.
 *
 * @property int $id
 * @property int $project_id
 * @property int $workflow_definition_id
 * @property string $current_state
 * @property int $transition_sequence
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read WorkflowDefinition $workflowDefinition
 * @property-read Collection<int, WorkflowTransition> $transitions
 */
#[Fillable([
    'project_id',
    'workflow_definition_id',
])]
final class WorkflowInstance extends Model
{
    /** @use HasFactory<WorkflowInstanceFactory> */
    use HasFactory;

    /**
     * Protect permanent aggregate bindings and workflow history.
     */
    protected static function booted(): void
    {
        self::updating(
            static function (self $instance): void {
                if (
                    $instance->isDirty('project_id')
                    || $instance->isDirty('workflow_definition_id')
                ) {
                    throw new LogicException(
                        'Workflow instance bindings are immutable.',
                    );
                }
            },
        );

        self::deleting(static function (): void {
            throw new LogicException(
                'Workflow instances cannot be deleted.',
            );
        });
    }

    /**
     * Return the project that owns this workflow instance.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the immutable definition version bound during creation.
     *
     * @return BelongsTo<WorkflowDefinition, $this>
     */
    public function workflowDefinition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class);
    }

    /**
     * Return committed transition history in deterministic sequence order.
     *
     * @return HasMany<WorkflowTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class)
            ->orderBy('sequence');
    }

    /**
     * Cast persisted values to stable PHP types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transition_sequence' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
