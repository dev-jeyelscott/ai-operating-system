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
 * @property int $identity_schema_version
 * @property string $approved_document_set_fingerprint
 * @property list<array{
 *     document_id: int,
 *     document_version_id: int,
 *     version: int,
 *     checksum_sha256: string,
 *     parsed_content_checksum_sha256: string,
 *     parsed_content_storage_disk: string,
 *     parsed_content_storage_path: string,
 *     analysis_flags: list<string>
 * }> $approved_document_versions
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'project_id',
    'project_configuration_version_id',
    'configuration_revision',
    'identity_schema_version',
    'approved_document_set_fingerprint',
    'approved_document_versions',
])]
final class ProjectContextSnapshot extends Model
{
    /** @use HasFactory<ProjectContextSnapshotFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Prevent application-level updates and deletions.
     */
    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException(
                'Project context snapshots are immutable.',
            );
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'Project context snapshots are immutable.',
            );
        });
    }

    /**
     * Return the project whose context was snapshotted.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the exact configuration revision used by the snapshot.
     *
     * @return BelongsTo<ProjectConfigurationVersion, $this>
     */
    public function configurationVersion(): BelongsTo
    {
        return $this->belongsTo(
            ProjectConfigurationVersion::class,
            'project_configuration_version_id',
        );
    }

    /**
     * Define immutable snapshot data casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'configuration_revision' => 'integer',
            'identity_schema_version' => 'integer',
            'approved_document_versions' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
