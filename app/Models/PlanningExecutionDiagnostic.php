<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $code
 * @property string $category
 * @property string $message
 * @property CarbonImmutable $created_at
 */
final class PlanningExecutionDiagnostic extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<ExecutionAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExecutionAttempt::class, 'execution_attempt_id');
    }

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
