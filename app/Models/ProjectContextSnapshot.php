<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProjectContextSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only authoritative context selected for one project operation.
 *
 * @property int $id
 * @property int $project_id
 * @property int $project_configuration_version_id
 * @property int $configuration_revision
 * @property list<array{document_id:int, document_version_id:int, version:int, checksum_sha256:string}> $approved_document_versions
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'project_id',
    'project_configuration_version_id',
    'configuration_revision',
    'approved_document_versions',
])]
final class ProjectContextSnapshot extends Model
{
    /** @use HasFactory<ProjectContextSnapshotFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('Project context snapshots are immutable.');
        });

        self::deleting(static function (): void {
            throw new LogicException('Project context snapshots are immutable.');
        });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectConfigurationVersion, $this> */
    public function configurationVersion(): BelongsTo
    {
        return $this->belongsTo(ProjectConfigurationVersion::class, 'project_configuration_version_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'configuration_revision' => 'integer',
            'approved_document_versions' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
