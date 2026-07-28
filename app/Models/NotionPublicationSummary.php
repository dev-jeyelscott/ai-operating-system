<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $created_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property int $failed_count
 * @property int $conflicted_count
 * @property array<int, array<string, mixed>>|null $outcomes
 * @property CarbonImmutable|null $completed_at
 */
#[Fillable(['organization_id', 'project_id', 'roadmap_id', 'request_fingerprint', 'correlation_id', 'outcomes', 'created_count', 'updated_count', 'skipped_count', 'failed_count', 'conflicted_count', 'completed_at'])]
final class NotionPublicationSummary extends Model
{
    /** @return BelongsTo<Roadmap, $this> */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
    }

    protected function casts(): array
    {
        return ['outcomes' => 'array', 'completed_at' => 'immutable_datetime'];
    }
}
