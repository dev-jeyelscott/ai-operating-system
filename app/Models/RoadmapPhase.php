<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $roadmap_id
 * @property string $stable_id
 * @property string $name
 * @property int $position
 * @property-read Collection<int, RoadmapMilestone> $milestones
 */
final class RoadmapPhase extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Roadmap, $this> */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
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
}
