<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WorkflowDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Stores one immutable version of a stable workflow definition.
 *
 * @property int $id
 * @property string $definition_key
 * @property int $version
 * @property int $schema_version
 * @property string $name
 * @property string|null $description
 * @property array{
 *     initial_state: string,
 *     states: list<string>,
 *     terminal_states: list<string>,
 *     transitions: list<array{
 *         name: string,
 *         from: string,
 *         to: string,
 *         guard: string|null
 *     }>
 * } $definition
 * @property string $checksum_sha256
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'definition_key',
    'version',
    'schema_version',
    'name',
    'description',
    'definition',
    'checksum_sha256',
    'created_at',
])]
final class WorkflowDefinition extends Model
{
    /** @use HasFactory<WorkflowDefinitionFactory> */
    use HasFactory;

    /**
     * Definition versions are append-only and never receive updated_at.
     */
    public $timestamps = false;

    /**
     * Reject application-level mutation of persisted definition versions.
     */
    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException(
                'Workflow definition versions are immutable.',
            );
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'Workflow definition versions cannot be deleted.',
            );
        });
    }

    /**
     * Cast persisted workflow-definition values to stable PHP types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema_version' => 'integer',
            'definition' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
