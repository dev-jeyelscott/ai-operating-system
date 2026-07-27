<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $roadmap_id
 * @property int $actor_user_id
 * @property int $base_content_version
 * @property int $content_version
 * @property array<string, mixed> $patch
 * @property string $resulting_fingerprint
 * @property string $idempotency_key_hash
 * @property string $request_fingerprint
 * @property CarbonImmutable $created_at
 * @property-read User $actor
 */
final class RoadmapEdit extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('Roadmap edits are immutable.');
        });
        self::deleting(static function (): void {
            throw new LogicException('Roadmap edits cannot be deleted.');
        });
    }

    /** @return BelongsTo<Roadmap, $this> */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['base_content_version' => 'integer', 'content_version' => 'integer', 'patch' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
