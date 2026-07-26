<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Executions\ExecutionAttemptStatus;
use App\Domain\Projects\Configuration\ReasoningLevel;
use Carbon\CarbonImmutable;
use Database\Factories\ExecutionAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Stores one ordered provider attempt for a logical execution.
 *
 * Error fields must contain redacted content only. Raw provider payloads,
 * credentials, prompts, and secrets do not belong in this record.
 *
 * @property int $id
 * @property string $execution_id
 * @property int $attempt_number
 * @property ExecutionAttemptStatus $status
 * @property string $execution_provider
 * @property string|null $model_identifier
 * @property ReasoningLevel $requested_reasoning_level
 * @property ReasoningLevel $effective_reasoning_level
 * @property string $reasoning_resolution_source
 * @property string|null $reasoning_escalation_reason
 * @property string|null $simulation_mode
 * @property string|null $simulation_seed
 * @property string|null $reported_state
 * @property string|null $observed_state
 * @property string|null $actual_state
 * @property string|null $confidence
 * @property string|null $estimated_cost
 * @property string|null $actual_cost
 * @property string|null $cost_currency
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Execution $execution
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'execution_id',
    'attempt_number',
    'execution_provider',
    'model_identifier',
    'requested_reasoning_level',
    'effective_reasoning_level',
    'reasoning_resolution_source',
    'reasoning_escalation_reason',
    'simulation_mode',
    'simulation_seed',
])]
final class ExecutionAttempt extends Model
{
    /** @use HasFactory<ExecutionAttemptFactory> */
    use HasFactory;

    /**
     * Define the default state before provider work starts.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
    ];

    /**
     * Protect attempt identity and the provider/reasoning snapshot.
     */
    protected static function booted(): void
    {
        self::updating(static function (self $attempt): void {
            foreach (
                [
                    'execution_id',
                    'attempt_number',
                    'execution_provider',
                    'model_identifier',
                    'requested_reasoning_level',
                    'effective_reasoning_level',
                    'reasoning_resolution_source',
                    'reasoning_escalation_reason',
                    'simulation_mode',
                    'simulation_seed',
                ] as $attribute
            ) {
                if ($attempt->isDirty($attribute)) {
                    throw new LogicException(
                        'Execution attempt identity and provider context are immutable.',
                    );
                }
            }
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'Execution attempts cannot be deleted.',
            );
        });
    }

    /**
     * Return the logical execution that owns this attempt.
     *
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * Cast persisted values to stable domain, decimal, and date types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'status' => ExecutionAttemptStatus::class,
            'requested_reasoning_level' => ReasoningLevel::class,
            'effective_reasoning_level' => ReasoningLevel::class,
            'confidence' => 'decimal:4',
            'estimated_cost' => 'decimal:8',
            'actual_cost' => 'decimal:8',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
