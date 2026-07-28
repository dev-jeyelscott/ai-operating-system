<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $roadmap_task_id
 * @property string $provider
 * @property string $external_key
 * @property string|null $page_id
 * @property string|null $page_url
 * @property string $state
 * @property array<string, mixed>|null $failure_metadata
 * @property string|null $reconciliation_state
 * @property string|null $reconciliation_fingerprint
 */
final class ExternalTicketMapping extends Model
{
    protected $fillable = [
        'roadmap_task_id',
        'provider',
        'external_key',
        'page_id',
        'page_url',
        'last_synchronized_fingerprint',
        'state',
        'failure_metadata',
        'last_provider_request_id',
        'attempt_count',
        'last_attempted_at',
        'last_published_at',
        'reconciliation_state',
        'reconciliation_fingerprint',
        'reconciled_at',
    ];

    /** @return BelongsTo<RoadmapTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(RoadmapTask::class, 'roadmap_task_id');
    }

    protected function casts(): array
    {
        return [
            'failure_metadata' => 'array',
            'attempt_count' => 'integer',
            'last_attempted_at' => 'immutable_datetime',
            'last_published_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
        ];
    }
}
