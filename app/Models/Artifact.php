<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ArtifactFactory;
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
 * Stores one immutable output reference produced by an execution attempt.
 *
 * Artifact content belongs in object storage or an immutable external system.
 * Secrets, raw prompts, credentials, and unredacted provider payloads must never
 * be persisted in this record.
 *
 * @property string $id
 * @property int $project_id
 * @property string $execution_id
 * @property int $execution_attempt_id
 * @property string $artifact_type
 * @property string $name
 * @property string $execution_provider
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property string|null $external_reference
 * @property string|null $media_type
 * @property string|null $checksum_sha256
 * @property int|null $byte_size
 * @property string|null $simulation_mode
 * @property string|null $simulation_seed
 * @property list<string> $assumptions
 * @property string|null $confidence
 * @property bool $evidence_still_required
 * @property string $actual_state
 * @property array<string, mixed> $metadata
 * @property CarbonImmutable $created_at
 * @property-read Project $project
 * @property-read Execution $execution
 * @property-read ExecutionAttempt $executionAttempt
 * @property-read Collection<int, Evidence> $evidence
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'project_id',
    'execution_id',
    'execution_attempt_id',
    'artifact_type',
    'name',
    'execution_provider',
    'storage_disk',
    'storage_path',
    'external_reference',
    'media_type',
    'checksum_sha256',
    'byte_size',
    'simulation_mode',
    'simulation_seed',
    'assumptions',
    'confidence',
    'evidence_still_required',
    'actual_state',
    'metadata',
])]
final class Artifact extends Model
{
    /** @use HasFactory<ArtifactFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Artifacts are append-only and therefore have no updated_at column.
     */
    public $timestamps = false;

    /**
     * Reject artifact mutation before PostgreSQL enforces the same invariant.
     */
    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('Artifacts are immutable.');
        });

        self::deleting(static function (): void {
            throw new LogicException('Artifacts cannot be deleted.');
        });
    }

    /**
     * Return the project that owns this artifact.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the logical execution that produced this artifact.
     *
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * Return the immutable provider attempt that produced this artifact.
     *
     * @return BelongsTo<ExecutionAttempt, $this>
     */
    public function executionAttempt(): BelongsTo
    {
        return $this->belongsTo(ExecutionAttempt::class);
    }

    /**
     * Return evidence records in deterministic creation order.
     *
     * @return HasMany<Evidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Scope a query to artifacts owned by one explicit project.
     *
     * @param  Builder<Artifact>  $query
     * @return Builder<Artifact>
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
     * Scope a query to one logical execution.
     *
     * @param  Builder<Artifact>  $query
     * @return Builder<Artifact>
     */
    public function scopeForExecution(
        Builder $query,
        string $executionId,
    ): Builder {
        return $query->where(
            $query->getModel()->qualifyColumn('execution_id'),
            $executionId,
        );
    }

    /**
     * Determine whether this artifact originated from the simulation provider.
     */
    public function isSimulated(): bool
    {
        return $this->execution_provider === 'simulation';
    }

    /**
     * Cast persisted provenance, JSON, decimal, and date values safely.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'assumptions' => 'array',
            'confidence' => 'decimal:4',
            'evidence_still_required' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
