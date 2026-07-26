<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stores one immutable domain-event envelope awaiting asynchronous delivery.
 *
 * Event identity and envelope fields are immutable after insertion. Dispatcher
 * fields may change only while reserving, publishing, or releasing a message.
 */
final class OutboxMessage extends Model
{
    /**
     * Outbox ordering uses the database-generated monotonic sequence.
     */
    protected $primaryKey = 'sequence';

    /**
     * The outbox sequence is an auto-incrementing integer.
     */
    public $incrementing = true;

    /**
     * The primary key is represented as an integer.
     */
    protected $keyType = 'int';

    /**
     * The table stores created_at but intentionally has no updated_at column.
     */
    public $timestamps = false;

    /**
     * Attributes allowed during event persistence and dispatcher updates.
     *
     * @var list<string>
     */
    protected $fillable = [
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
        'available_at',
        'reserved_until',
        'reservation_token',
        'dispatch_attempts',
        'last_error',
        'created_at',
    ];

    /**
     * Define strict persistence casts for event and dispatcher metadata.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'organization_id' => 'integer',
            'project_id' => 'integer',
            'schema_version' => 'integer',
            'dispatch_attempts' => 'integer',
            'envelope' => 'array',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'reserved_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
