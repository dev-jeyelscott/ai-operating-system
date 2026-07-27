<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $roadmap_id
 * @property int $roadmap_task_id
 * @property string $criterion_stable_id
 * @property int $project_context_snapshot_id
 * @property int $document_id
 * @property int $document_version_id
 * @property int $document_version
 * @property string $checksum_sha256
 * @property-read RoadmapTask $task
 * @property-read DocumentVersion $documentVersion
 */
final class RoadmapTraceabilityLink extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<Roadmap, $this> */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
    }

    /** @return BelongsTo<RoadmapTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(RoadmapTask::class, 'roadmap_task_id');
    }

    /** @return BelongsTo<ProjectContextSnapshot, $this> */
    public function contextSnapshot(): BelongsTo
    {
        return $this->belongsTo(ProjectContextSnapshot::class, 'project_context_snapshot_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    protected function casts(): array
    {
        return ['document_version' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
