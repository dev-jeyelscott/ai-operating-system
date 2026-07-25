<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Documents\DocumentClassification;
use App\Domain\Documents\DocumentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Immutable file identity and mutable processing metadata for one document revision.
 *
 * @property int $id
 * @property int $document_id
 * @property int $version
 * @property string $original_filename
 * @property string $media_type
 * @property int $byte_size
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $checksum_sha256
 * @property DocumentStatus $status
 * @property DocumentClassification $classification
 * @property string|null $parser_name
 * @property string|null $parser_version
 * @property CarbonImmutable|null $parsing_started_at
 * @property CarbonImmutable|null $parsed_at
 * @property string|null $parsed_content
 * @property string|null $analyzer_name
 * @property string|null $analyzer_version
 * @property int|null $analysis_seed
 * @property CarbonImmutable|null $analysis_started_at
 * @property CarbonImmutable|null $analysis_completed_at
 * @property string|null $analysis_summary
 * @property list<string>|null $analysis_conflicts
 * @property list<string>|null $analysis_gaps
 * @property list<string>|null $analysis_flags
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int|null $supersedes_document_version_id
 * @property-read Document $document
 * @property-read DocumentVersion|null $supersedes
 * @property-read Collection<int, DocumentVersion> $supersededBy
 */
#[Fillable([
    'document_id',
    'version',
    'original_filename',
    'media_type',
    'byte_size',
    'storage_disk',
    'storage_path',
    'checksum_sha256',
    'status',
    'classification',
    'parser_name',
    'parser_version',
    'parsing_started_at',
    'parsed_at',
    'parsed_content',
    'analyzer_name',
    'analyzer_version',
    'analysis_seed',
    'analysis_started_at',
    'analysis_completed_at',
    'analysis_summary',
    'analysis_conflicts',
    'analysis_gaps',
    'analysis_flags',
    'failure_code',
    'failure_message',
    'supersedes_document_version_id',
])]
final class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    /**
     * Fields that define the immutable identity of one uploaded revision.
     *
     * @var list<string>
     */
    private const IMMUTABLE_FILE_IDENTITY = [
        'document_id',
        'version',
        'original_filename',
        'media_type',
        'byte_size',
        'storage_disk',
        'storage_path',
        'checksum_sha256',
        'supersedes_document_version_id',
    ];

    /**
     * Analysis and safety fields frozen after analysis reaches review.
     *
     * @var list<string>
     */
    private const IMMUTABLE_COMPLETED_ANALYSIS = [
        'classification',
        'parser_name',
        'parser_version',
        'parsing_started_at',
        'parsed_at',
        'parsed_content',
        'analyzer_name',
        'analyzer_version',
        'analysis_seed',
        'analysis_started_at',
        'analysis_completed_at',
        'analysis_summary',
        'analysis_conflicts',
        'analysis_gaps',
        'analysis_flags',
        'failure_code',
        'failure_message',
    ];

    /**
     * States whose completed analysis is historical evidence.
     *
     * @var list<string>
     */
    private const COMPLETED_ANALYSIS_STATUSES = [
        'needs_review',
        'approved',
        'rejected',
        'superseded',
    ];

    /**
     * Prevent mutation of revision identity and completed safety evidence.
     */
    protected static function booted(): void
    {
        self::updating(
            static function (
                self $documentVersion,
            ): void {
                if (
                    $documentVersion->isDirty(
                        self::IMMUTABLE_FILE_IDENTITY,
                    )
                ) {
                    throw new LogicException(
                        'Document version file identity is immutable.',
                    );
                }

                $originalStatus = (string) $documentVersion
                    ->getRawOriginal('status');

                if (
                    in_array(
                        $originalStatus,
                        self::COMPLETED_ANALYSIS_STATUSES,
                        true,
                    )
                    && $documentVersion->isDirty(
                        self::IMMUTABLE_COMPLETED_ANALYSIS,
                    )
                ) {
                    throw new LogicException(
                        'Completed document analysis metadata is immutable.',
                    );
                }
            },
        );
    }

    /**
     * Start parsing only after a completed, approved malware scan.
     */
    public function beginParsing(
        string $parserName,
        string $parserVersion,
    ): void {
        if ($this->status !== DocumentStatus::ScanApproved) {
            throw new LogicException(
                'A document version cannot be parsed before scan approval.',
            );
        }

        $this->forceFill([
            'status' => DocumentStatus::Parsing,
            'parser_name' => $parserName,
            'parser_version' => $parserVersion,
            'parsing_started_at' => now(),
            'failure_code' => null,
            'failure_message' => null,
        ])->save();
    }

    /**
     * Start deterministic analysis from the explicit pending state.
     */
    public function beginAnalysis(
        string $analyzerName,
        string $analyzerVersion,
        int $seed,
    ): void {
        if ($this->status !== DocumentStatus::AnalysisPending) {
            throw new LogicException(
                'Document analysis can only start from the pending state.',
            );
        }

        $this->forceFill([
            'status' => DocumentStatus::Analyzing,
            'analyzer_name' => $analyzerName,
            'analyzer_version' => $analyzerVersion,
            'analysis_seed' => $seed,
            'analysis_started_at' => now(),
            'analysis_completed_at' => null,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();
    }

    /**
     * Determine whether every required analysis result and provenance field exists.
     */
    public function isReadyForReview(): bool
    {
        return $this->status === DocumentStatus::NeedsReview
            && $this->classification
            !== DocumentClassification::Unclassified
            && is_string($this->analyzer_name)
            && trim($this->analyzer_name) !== ''
            && is_string($this->analyzer_version)
            && trim($this->analyzer_version) !== ''
            && $this->analysis_seed !== null
            && $this->analysis_completed_at !== null
            && is_string($this->analysis_summary)
            && is_array($this->analysis_conflicts)
            && is_array($this->analysis_gaps)
            && is_array($this->analysis_flags);
    }

    /**
     * Determine whether this failed processing stage may be safely retried.
     *
     * Permanent parser capability failures require a replacement upload instead
     * of repeatedly dispatching work that can never succeed.
     */
    public function canRetryProcessing(): bool
    {
        return match ($this->status) {
            DocumentStatus::ScanFailed,
            DocumentStatus::AnalysisFailed => true,

            DocumentStatus::ParseFailed => $this->failure_code
                !== 'unsupported_media_type',

            default => false,
        };
    }

    /**
     * Return the owning document.
     *
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Return the prior version this version supersedes.
     *
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'supersedes_document_version_id',
        );
    }

    /**
     * Return versions created from this version.
     *
     * @return HasMany<DocumentVersion, $this>
     */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(
            self::class,
            'supersedes_document_version_id',
        );
    }

    /**
     * Define enum, array, numeric, and immutable timestamp casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'byte_size' => 'integer',
            'status' => DocumentStatus::class,
            'classification' => DocumentClassification::class,
            'parsing_started_at' => 'immutable_datetime',
            'parsed_at' => 'immutable_datetime',
            'analysis_seed' => 'integer',
            'analysis_started_at' => 'immutable_datetime',
            'analysis_completed_at' => 'immutable_datetime',
            'analysis_conflicts' => 'array',
            'analysis_gaps' => 'array',
            'analysis_flags' => 'array',
        ];
    }
}
