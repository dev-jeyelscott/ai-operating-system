<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'project_id', 'external_ticket_mapping_id', 'state', 'current_fingerprint', 'published_fingerprint', 'external_fingerprint', 'decision', 'decision_reason', 'decided_by_user_id', 'decided_at', 'resulting_roadmap_id'])]
final class NotionReconciliationConflict extends Model
{
    /** @return BelongsTo<ExternalTicketMapping, $this> */
    public function mapping(): BelongsTo
    {
        return $this->belongsTo(ExternalTicketMapping::class, 'external_ticket_mapping_id');
    }

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }
}
