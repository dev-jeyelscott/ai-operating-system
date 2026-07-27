<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExternalTicketMapping extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<RoadmapTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(RoadmapTask::class, 'roadmap_task_id');
    }

    protected function casts(): array
    {
        return ['failure_metadata' => 'array'];
    }
}
