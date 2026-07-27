<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id @property int $roadmap_phase_id @property string $stable_id @property string $name */
final class RoadmapMilestone extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Roadmap, $this> */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
    }

    /** @return BelongsTo<RoadmapPhase, $this> */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(RoadmapPhase::class, 'roadmap_phase_id');
    }

    /** @return HasMany<RoadmapTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(RoadmapTask::class)->orderBy('position');
    }
}
