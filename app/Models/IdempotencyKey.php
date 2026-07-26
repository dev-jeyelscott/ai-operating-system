<?php

declare(strict_types=1);

namespace App\Models;

use App\Application\Shared\Commands\CommandResultStatus;
use App\Domain\Idempotency\IdempotencyKeyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a durable idempotency claim and its terminal command result.
 *
 * @property string $id
 * @property string $scope
 * @property string $key_hash
 * @property class-string $command_class
 * @property string $request_fingerprint
 * @property IdempotencyKeyStatus $status
 * @property CommandResultStatus|null $result_status
 * @property array<string, mixed>|null $result_payload
 * @property string|null $lock_owner
 * @property CarbonImmutable|null $lock_expires_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'scope',
    'key_hash',
    'command_class',
    'request_fingerprint',
    'status',
    'result_status',
    'result_payload',
    'lock_owner',
    'lock_expires_at',
    'completed_at',
    'expires_at',
])]
final class IdempotencyKey extends Model
{
    use HasUlids;

    /**
     * Define the model's type-safe attribute casts.
     *
     * The stored command result is encrypted because command-result data may
     * contain internal identifiers or other operational metadata.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IdempotencyKeyStatus::class,
            'result_status' => CommandResultStatus::class,
            'result_payload' => 'encrypted:array',
            'lock_expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
