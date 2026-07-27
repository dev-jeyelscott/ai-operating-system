<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Stores one successfully committed workflow transition.
 *
 * Rejected or failed transition attempts do not belong in this table.
 * AIOS-049 and later audit/event work will record attempted operations.
 *
 * @property int $id
 * @property int $workflow_instance_id
 * @property int $sequence
 * @property string $name
 * @property string $from_state
 * @property string $to_state
 * @property string|null $guard
 * @property CarbonImmutable $created_at
 * @property-read WorkflowInstance $workflowInstance
 */
final class WorkflowTransition extends Model
{
    /**
     * Transition records are append-only and do not receive updated_at.
     */
    public $timestamps = false;

    /**
     * Reject application-level mutation of committed transition history.
     */
    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException(
                'Workflow transitions are immutable.',
            );
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'Workflow transitions cannot be deleted.',
            );
        });
    }

    /**
     * Return the workflow instance that owns this transition.
     *
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    /**
     * Cast persisted values to stable PHP types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
