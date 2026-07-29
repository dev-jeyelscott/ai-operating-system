<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Evidence\EvidenceClassification;
use Carbon\CarbonImmutable;
use Database\Factories\EvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Stores one immutable claim, observation, verification, or rejection record.
 *
 * A stronger classification must be recorded as a new row. Existing evidence is
 * never upgraded in place because doing so would erase the original provenance.
 *
 * @property string $id
 * @property string $artifact_id
 * @property EvidenceClassification $classification
 * @property string $evidence_type
 * @property string $provider
 * @property string $source_reference
 * @property string|null $commit_sha
 * @property list<string> $claims
 * @property string|null $verification_method
 * @property CarbonImmutable|null $observed_at
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $expires_at
 * @property string|null $rejection_reason
 * @property string|null $confidence
 * @property array<string, mixed> $metadata
 * @property string|null $provenance_key
 * @property string|null $content_fingerprint_sha256
 * @property CarbonImmutable $created_at
 * @property-read Artifact $artifact
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'artifact_id',
    'classification',
    'evidence_type',
    'provider',
    'source_reference',
    'commit_sha',
    'claims',
    'verification_method',
    'observed_at',
    'verified_at',
    'expires_at',
    'rejection_reason',
    'confidence',
    'metadata',
    'provenance_key',
    'content_fingerprint_sha256',
])]
final class Evidence extends Model
{
    /** @use HasFactory<EvidenceFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Use the approved uncountable table name from the system specification.
     */
    protected $table = 'evidence';

    /**
     * Evidence is append-only and therefore has no updated_at column.
     */
    public $timestamps = false;

    /**
     * Reject evidence mutation before PostgreSQL enforces the same invariant.
     */
    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('Evidence records are immutable.');
        });

        self::deleting(static function (): void {
            throw new LogicException('Evidence records cannot be deleted.');
        });
    }

    /**
     * Return the artifact described by this evidence record.
     *
     * @return BelongsTo<Artifact, $this>
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    /**
     * Scope evidence through artifacts owned by one explicit project.
     *
     * @param  Builder<Evidence>  $query
     * @return Builder<Evidence>
     */
    public function scopeForProject(
        Builder $query,
        int $projectId,
    ): Builder {
        return $query->whereIn(
            $query->getModel()->qualifyColumn('artifact_id'),
            Artifact::query()
                ->forProject($projectId)
                ->select('id'),
        );
    }

    /**
     * Scope a query to one explicit evidence classification.
     *
     * @param  Builder<Evidence>  $query
     * @return Builder<Evidence>
     */
    public function scopeClassifiedAs(
        Builder $query,
        EvidenceClassification $classification,
    ): Builder {
        return $query->where(
            $query->getModel()->qualifyColumn('classification'),
            $classification,
        );
    }

    /**
     * Determine whether this record is verified evidence.
     */
    public function isVerified(): bool
    {
        return $this->classification->isVerified();
    }

    /**
     * Cast persisted classification, JSON, decimal, and date values safely.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classification' => EvidenceClassification::class,
            'claims' => 'array',
            'observed_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'confidence' => 'decimal:4',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
