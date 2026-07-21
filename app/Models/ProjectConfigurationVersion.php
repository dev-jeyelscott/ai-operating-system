<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\AuditActorType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Stores one immutable full snapshot of project configuration.
 *
 * @property int $id
 * @property int $project_id
 * @property int $schema_version
 * @property int $revision
 * @property AuditActorType $actor_type
 * @property string $actor_id
 * @property string $change_reason
 * @property array<string, mixed> $snapshot
 * @property CarbonImmutable $created_at
 * @property-read Project $project
 */
#[Fillable([
    'project_id',
    'schema_version',
    'revision',
    'actor_type',
    'actor_id',
    'change_reason',
    'snapshot',
    'created_at',
])]
final class ProjectConfigurationVersion extends Model
{
    /**
     * History rows have created_at but never updated_at.
     */
    public $timestamps = false;

    /**
     * Return the project whose configuration produced this snapshot.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Register fail-fast application guards for forbidden mutations.
     *
     * PostgreSQL independently rejects these operations so Query Builder and
     * direct SQL writes cannot bypass the invariant.
     */
    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException(
                'Project configuration versions are immutable.',
            );
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'Project configuration versions cannot be deleted.',
            );
        });
    }

    /**
     * Cast persisted history values to domain-safe types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'revision' => 'integer',
            'actor_type' => AuditActorType::class,
            'snapshot' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
