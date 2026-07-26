<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Executions\ExecutionStatus;
use App\Domain\Projects\Configuration\ReasoningLevel;
use Carbon\CarbonImmutable;
use Database\Factories\ExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Stores the current summary for one logical workflow execution.
 *
 * Provider-specific outcomes belong to execution attempts so retries and
 * fallbacks cannot overwrite the historical record of an earlier attempt.
 *
 * @property string $id
 * @property int $project_id
 * @property int|null $workflow_instance_id
 * @property string $capability
 * @property string|null $logical_role
 * @property ExecutionStatus $status
 * @property ReasoningLevel $requested_reasoning_level
 * @property int $attempt_count
 * @property string $correlation_id
 * @property string $idempotency_key
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read WorkflowInstance|null $workflowInstance
 * @property-read Collection<int, ExecutionAttempt> $attempts
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'project_id',
    'workflow_instance_id',
    'capability',
    'logical_role',
    'requested_reasoning_level',
    'correlation_id',
    'idempotency_key',
])]
final class Execution extends Model
{
    /** @use HasFactory<ExecutionFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Define deterministic defaults for a newly queued execution.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
        'attempt_count' => 0,
    ];

    /**
     * Protect execution identity and immutable request context.
     */
    protected static function booted(): void
    {
        self::updating(static function (self $execution): void {
            foreach (
                [
                    'project_id',
                    'workflow_instance_id',
                    'capability',
                    'logical_role',
                    'requested_reasoning_level',
                    'correlation_id',
                    'idempotency_key',
                ] as $attribute
            ) {
                if ($execution->isDirty($attribute)) {
                    throw new LogicException(
                        'Execution identity and request context are immutable.',
                    );
                }
            }
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'Executions cannot be deleted.',
            );
        });
    }

    /**
     * Return the project that owns this execution.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the workflow instance coordinating this execution, if any.
     *
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    /**
     * Return attempts in deterministic attempt-number order.
     *
     * @return HasMany<ExecutionAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(ExecutionAttempt::class)
            ->orderBy('attempt_number');
    }

    /**
     * Scope a query to executions belonging to one explicit project.
     *
     * @param  Builder<Execution>  $query
     * @return Builder<Execution>
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
     * Cast persisted values to stable domain and date types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExecutionStatus::class,
            'requested_reasoning_level' => ReasoningLevel::class,
            'attempt_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
