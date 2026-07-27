<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Policies\ReasoningResolutionSource;
use App\Domain\Projects\Configuration\ReasoningLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Stores one immutable deterministic policy decision for an execution.
 *
 * @property string $id
 * @property int $project_id
 * @property string $execution_id
 * @property string $decision_type
 * @property int $policy_version
 * @property ReasoningLevel $requested_reasoning_level
 * @property ReasoningResolutionSource $requested_reasoning_source
 * @property ReasoningLevel $effective_reasoning_level
 * @property ReasoningResolutionSource $reasoning_resolution_source
 * @property string|null $reasoning_escalation_reason
 * @property list<string> $reasoning_escalation_reasons
 * @property array<string, mixed> $input_snapshot
 * @property string $input_fingerprint
 * @property CarbonImmutable $created_at
 * @property-read Project $project
 * @property-read Execution $execution
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'project_id',
    'execution_id',
    'decision_type',
    'policy_version',
    'requested_reasoning_level',
    'requested_reasoning_source',
    'effective_reasoning_level',
    'reasoning_resolution_source',
    'reasoning_escalation_reason',
    'reasoning_escalation_reasons',
    'input_snapshot',
    'input_fingerprint',
])]
final class PolicyDecision extends Model
{
    use HasUlids;

    public const string REASONING_RESOLUTION = 'reasoning_resolution';

    /**
     * Policy decisions are append-only and therefore have no updated_at column.
     */
    public $timestamps = false;

    /**
     * Reject mutations before PostgreSQL enforces the same invariant.
     */
    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('Policy decisions are immutable.');
        });

        self::deleting(static function (): void {
            throw new LogicException('Policy decisions cannot be deleted.');
        });
    }

    /**
     * Return the project that owns this policy decision.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the execution whose provider attempts consume this decision.
     *
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * Scope a query to one explicit tenant-owned project.
     *
     * @param  Builder<PolicyDecision>  $query
     * @return Builder<PolicyDecision>
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
     * Cast persisted policy values to stable domain and date types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'policy_version' => 'integer',
            'requested_reasoning_level' => ReasoningLevel::class,
            'requested_reasoning_source' => ReasoningResolutionSource::class,
            'effective_reasoning_level' => ReasoningLevel::class,
            'reasoning_resolution_source' => ReasoningResolutionSource::class,
            'reasoning_escalation_reasons' => 'array',
            'input_snapshot' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
