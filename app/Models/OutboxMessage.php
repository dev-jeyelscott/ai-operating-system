<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable persistence record for one canonical domain-event envelope.
 *
 * The event envelope is immutable after insertion. Only delivery metadata,
 * currently published_at, may be changed by the future dispatcher.
 *
 * @property int $sequence
 * @property string $event_id
 * @property string $event_name
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property int $organization_id
 * @property int|null $project_id
 * @property CarbonImmutable $occurred_at
 * @property string $correlation_id
 * @property string|null $causation_id
 * @property string|null $execution_id
 * @property int $schema_version
 * @property array<string, mixed> $envelope
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'event_id',
    'event_name',
    'aggregate_type',
    'aggregate_id',
    'organization_id',
    'project_id',
    'occurred_at',
    'correlation_id',
    'causation_id',
    'execution_id',
    'schema_version',
    'envelope',
    'published_at',
])]
final class OutboxMessage extends Model
{
    protected $table = 'outbox_messages';

    protected $primaryKey = 'sequence';

    protected $keyType = 'int';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * Cast persisted attributes to stable application types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'project_id' => 'integer',
            'schema_version' => 'integer',
            'envelope' => 'array',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
